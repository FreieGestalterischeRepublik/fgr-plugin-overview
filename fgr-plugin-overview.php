<?php
/**
 * Plugin Name:  FGR Plugin-Übersicht MU
 * Description:  Zeigt immer das Menü "FGR Plugins" im Backend – auch wenn keine Plugins aktiv sind.
 *               Verwendet dieselben Funktionsnamen wie fgr-hide-login, damit kein doppeltes Menü entsteht.
 * Version:      1.6.0
 * Author:       Freie Gestalterische Republik
 */

defined( 'ABSPATH' ) || exit;

// ── MU-Plugin-Sync ────────────────────────────────────────────────────────────

if ( ! function_exists( 'fgr_mu_sync' ) ) {
    function fgr_mu_sync(): bool {
        $url      = 'https://raw.githubusercontent.com/FreieGestalterischeRepublik/fgr-plugin-overview/main/fgr-plugin-overview.php';
        $dest_dir = WPMU_PLUGIN_DIR;
        $dest     = $dest_dir . '/fgr-plugin-overview.php';

        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        ] );

        if ( is_wp_error( $response ) ) return false;
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return false;

        $remote_content = wp_remote_retrieve_body( $response );
        if ( empty( $remote_content ) ) return false;

        preg_match( '/\*\s+Version:\s+([\d.]+)/i', $remote_content, $matches );
        $remote_version = $matches[1] ?? '0';

        $installed_version = fgr_mu_installed_version();

        if ( ! file_exists( $dest ) || version_compare( $remote_version, $installed_version, '>' ) ) {
            if ( ! is_dir( $dest_dir ) ) {
                wp_mkdir_p( $dest_dir );
            }
            $written = file_put_contents( $dest, $remote_content );
            if ( false === $written ) return false;
            delete_transient( 'fgr_mu_update_info' );
        }

        return true;
    }
}

// ── Hilfsfunktionen für Update-Check ─────────────────────────────────────────

function fgr_mu_installed_version(): string {
    $file = WPMU_PLUGIN_DIR . '/fgr-plugin-overview.php';
    if ( ! file_exists( $file ) ) return '0';
    $contents = file_get_contents( $file );
    preg_match( '/\*\s+Version:\s+([\d.]+)/i', $contents, $m );
    return $m[1] ?? '0';
}

function fgr_mu_get_update_info( bool $force = false ): array {
    if ( ! $force ) {
        $cached = get_transient( 'fgr_mu_update_info' );
        if ( false !== $cached ) return $cached;
    }

    $current = fgr_mu_installed_version();

    $response = wp_remote_get(
        'https://api.github.com/repos/FreieGestalterischeRepublik/fgr-plugin-overview/releases/latest',
        [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        ]
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return [
            'current'          => $current,
            'latest'           => $current,
            'update_available' => false,
            'error'            => is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ),
        ];
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $latest = ltrim( $body['tag_name'] ?? $current, 'v' );

    $info = [
        'current'          => $current,
        'latest'           => $latest,
        'update_available' => version_compare( $latest, $current, '>' ),
    ];

    set_transient( 'fgr_mu_update_info', $info, 6 * HOUR_IN_SECONDS );

    return $info;
}

// ── AJAX Handler: Update suchen ───────────────────────────────────────────────

add_action( 'wp_ajax_fgr_mu_check_update', 'fgr_mu_check_update_handler' );

function fgr_mu_check_update_handler(): void {
    check_ajax_referer( 'fgr_mu_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }
    delete_transient( 'fgr_mu_update_info' );
    $info = fgr_mu_get_update_info( true );
    wp_send_json_success( $info );
}

// ── AJAX Handler: Update durchführen ─────────────────────────────────────────

add_action( 'wp_ajax_fgr_mu_do_update', 'fgr_mu_do_update_handler' );

function fgr_mu_do_update_handler(): void {
    check_ajax_referer( 'fgr_mu_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }
    $ok = fgr_mu_sync();
    if ( ! $ok ) {
        wp_send_json_error( 'Update fehlgeschlagen. Mögliche Ursachen: keine Schreibrechte auf mu-plugins/, GitHub nicht erreichbar.' );
    }
    delete_transient( 'fgr_mu_update_info' );
    $new_version = fgr_mu_installed_version();
    wp_send_json_success( [ 'version' => $new_version ] );
}

// ── upgrader_process_complete Hook ───────────────────────────────────────────

add_action( 'upgrader_process_complete', 'fgr_mu_upgrader_hook', 10, 2 );

function fgr_mu_upgrader_hook( $upgrader, array $hook_extra ): void {
    if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) return;

    $action = $hook_extra['action'] ?? '';

    if ( $action === 'install' ) {
        fgr_mu_sync();
        return;
    }

    if ( $action === 'update' ) {
        $fgr_plugins = [
            'fgr-mail-smtp/fgr-mail-smtp.php',
            'fgr-hide-login/fgr-hide-login.php',
            'fgr-maintenance/fgr-maintenance.php',
            'fgr-email-encoder/fgr-email-encoder.php',
        ];
        $updated = array_merge(
            isset( $hook_extra['plugin'] )  ? (array) $hook_extra['plugin']  : [],
            isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : []
        );
        foreach ( $updated as $plugin_file ) {
            if ( in_array( $plugin_file, $fgr_plugins, true ) ) {
                fgr_mu_sync();
                return;
            }
        }
    }
}

// ── PUC für inaktive FGR-Plugins + Plugin-Listenlinks ────────────────────────

add_action( 'plugins_loaded', function (): void {
    $fgr_plugins = [
        'fgr-mail-smtp'      => 'fgr-mail-smtp/fgr-mail-smtp.php',
        'fgr-hide-login'     => 'fgr-hide-login/fgr-hide-login.php',
        'fgr-maintenance'    => 'fgr-maintenance/fgr-maintenance.php',
        'fgr-email-encoder'  => 'fgr-email-encoder/fgr-email-encoder.php',
    ];

    $active_plugins = (array) get_option( 'active_plugins', [] );

    if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        foreach ( array_keys( $fgr_plugins ) as $slug ) {
            $lib = WP_PLUGIN_DIR . '/' . $slug . '/lib/plugin-update-checker/plugin-update-checker.php';
            if ( file_exists( $lib ) ) {
                require_once $lib;
                break;
            }
        }
    }

    if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        foreach ( $fgr_plugins as $slug => $plugin_file ) {
            if ( in_array( $plugin_file, $active_plugins, true ) ) continue;
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( ! file_exists( $plugin_path ) ) continue;

            $updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/FreieGestalterischeRepublik/' . $slug . '/',
                $plugin_path,
                $slug
            );
            $updater->setBranch( 'main' );
            $updater->getVcsApi()->enableReleaseAssets();
        }
    }

    add_filter( 'plugin_row_meta', function ( array $links, string $plugin_file ) use ( $fgr_plugins ): array {
        if ( ! in_array( $plugin_file, $fgr_plugins, true ) ) {
            return $links;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }

        $slug = (string) array_search( $plugin_file, $fgr_plugins, true );

        $has_details = false;
        $has_check   = false;
        foreach ( $links as $link ) {
            if ( strpos( $link, 'open-plugin-details-modal' ) !== false ) $has_details = true;
            if ( strpos( $link, 'puc_check_for_updates' )     !== false ) $has_check   = true;
        }

        if ( ! $has_details ) {
            $url     = network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( $slug ) . '&TB_iframe=true&width=600&height=550' );
            $links[] = '<a href="' . esc_url( $url ) . '" class="thickbox open-plugin-details-modal">Details anzeigen</a>';
        }
        if ( ! $has_check ) {
            $url     = wp_nonce_url(
                add_query_arg( [ 'puc_check_for_updates' => 1, 'puc_slug' => $slug ], self_admin_url( 'plugins.php' ) ),
                'puc_check_for_updates'
            );
            $links[] = '<a href="' . esc_url( $url ) . '">Nach Update suchen</a>';
        }
        return $links;
    }, 20, 2 );

}, 99 );

// ── Gemeinsamer FGR-Admin-Menüpunkt ──────────────────────────────────────────

if ( ! function_exists( 'fgr_register_admin_menu' ) ) {

    function fgr_register_admin_menu(): void {
        add_menu_page(
            'FGR Plugins',
            'FGR Plugins',
            'manage_options',
            'fgr-plugins',
            'fgr_render_plugins_overview',
            'dashicons-shield',
            65
        );
        add_submenu_page(
            'fgr-plugins',
            'FGR Plugins',
            'Übersicht',
            'manage_options',
            'fgr-plugins',
            'fgr_render_plugins_overview'
        );
    }
    add_action( 'admin_menu', 'fgr_register_admin_menu', 5 );

    function fgr_render_plugins_overview(): void {
        $plugins = [
            [
                'slug' => 'fgr-mail-smtp',
                'file' => 'fgr-mail-smtp/fgr-mail-smtp.php',
                'name' => 'FGR Mail SMTP',
                'desc' => 'E-Mails über SMTP oder Microsoft 365 versenden',
                'page' => 'fgr-mail-smtp',
            ],
            [
                'slug' => 'fgr-hide-login',
                'file' => 'fgr-hide-login/fgr-hide-login.php',
                'name' => 'FGR Hide Login',
                'desc' => 'Login-URL individuell anpassen und schützen',
                'page' => 'fgr-hide-login',
            ],
            [
                'slug' => 'fgr-maintenance',
                'file' => 'fgr-maintenance/fgr-maintenance.php',
                'name' => 'FGR Maintenance',
                'desc' => 'Under-Construction- oder Wartungsseite anzeigen',
                'page' => 'fgr-maintenance',
            ],
            [
                'slug' => 'fgr-email-encoder',
                'file' => 'fgr-email-encoder/fgr-email-encoder.php',
                'name' => 'FGR Email Encoder',
                'desc' => 'E-Mail-Adressen vor Spam-Bots schützen',
                'page' => 'fgr-email-encoder',
            ],
        ];
        ?>
        <div class="wrap">
            <h1>FGR Plugins</h1>
            <p style="color:#888;margin-top:-8px">von der <em>Freien Gestalterischen Republik</em></p>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:20px">
            <?php foreach ( $plugins as $p ) :
                $active    = is_plugin_active( $p['file'] );
                $installed = file_exists( WP_PLUGIN_DIR . '/' . $p['file'] );
                if ( $active ) {
                    $badge = '<span style="color:#46b450;font-size:12px">&#9679; Aktiv</span>';
                } elseif ( $installed ) {
                    $badge = '<span style="color:#888;font-size:12px">&#9679; Inaktiv</span>';
                } else {
                    $badge = '<span style="color:#dc3545;font-size:12px">&#9679; Nicht installiert</span>';
                }
            ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;min-width:240px;max-width:320px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
                        <h2 style="margin:0"><?php echo esc_html( $p['name'] ); ?></h2>
                        <?php echo $badge; // phpcs:ignore ?>
                    </div>
                    <p style="color:#555;margin-bottom:16px"><?php echo esc_html( $p['desc'] ); ?></p>
                    <?php if ( $active ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $p['page'] ) ); ?>"
                           class="button button-primary">Einstellungen</a>
                    <?php elseif ( $installed ) : ?>
                        <a href="<?php echo esc_url( wp_nonce_url(
                            admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $p['file'] ) ),
                            'activate-plugin_' . $p['file']
                        ) ); ?>" class="button button-primary">Aktivieren</a>
                    <?php else : ?>
                        <button type="button" class="button button-primary fgr-install-btn"
                                data-slug="<?php echo esc_attr( $p['slug'] ); ?>">
                            Installieren
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php
            $mu_info    = get_transient( 'fgr_mu_update_info' );
            $mu_version = fgr_mu_installed_version();
            $has_update = is_array( $mu_info ) && ! empty( $mu_info['update_available'] );
            $nonce      = wp_create_nonce( 'fgr_mu_nonce' );
            ?>
            <div style="margin-top:32px;padding:16px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;display:inline-flex;align-items:center;gap:16px">
                <span style="color:#555">
                    <strong>FGR Plugin-Übersicht MU</strong>
                    &nbsp;·&nbsp; Version <?php echo esc_html( $mu_version ); ?>
                </span>
                <?php if ( $has_update ) : ?>
                    <button type="button" id="fgr-mu-do-update" class="button button-primary"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            data-version="<?php echo esc_attr( $mu_info['latest'] ); ?>">
                        Update <?php echo esc_html( $mu_info['latest'] ); ?> durchführen
                    </button>
                <?php else : ?>
                    <button type="button" id="fgr-mu-check-update" class="button"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        Update suchen
                    </button>
                <?php endif; ?>
                <span id="fgr-mu-msg" style="color:#555;font-size:13px"></span>
            </div>
        </div>

        <script>
        (function () {
            var checkBtn  = document.getElementById( 'fgr-mu-check-update' );
            var updateBtn = document.getElementById( 'fgr-mu-do-update' );
            var msg       = document.getElementById( 'fgr-mu-msg' );

            if ( checkBtn ) {
                checkBtn.addEventListener( 'click', function () {
                    var btn = this;
                    btn.disabled    = true;
                    btn.textContent = 'Suche…';
                    msg.textContent = '';
                    fetch( ajaxurl, {
                        method: 'POST',
                        body:   new URLSearchParams( {
                            action:      'fgr_mu_check_update',
                            _ajax_nonce: btn.dataset.nonce
                        } )
                    } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( data ) {
                        if ( data.success ) {
                            if ( data.data.update_available ) {
                                location.reload();
                            } else {
                                btn.disabled    = false;
                                btn.textContent = 'Update suchen';
                                msg.textContent = 'Bereits aktuell (v' + data.data.current + ')';
                            }
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Update suchen';
                            msg.textContent = 'Fehler: ' + ( data.data || 'Unbekannt' );
                        }
                    } )
                    .catch( function () {
                        btn.disabled    = false;
                        btn.textContent = 'Update suchen';
                        msg.textContent = 'Verbindungsfehler.';
                    } );
                } );
            }

            if ( updateBtn ) {
                updateBtn.addEventListener( 'click', function () {
                    var btn = this;
                    btn.disabled    = true;
                    btn.textContent = 'Aktualisiere…';
                    msg.textContent = '';
                    fetch( ajaxurl, {
                        method: 'POST',
                        body:   new URLSearchParams( {
                            action:      'fgr_mu_do_update',
                            _ajax_nonce: btn.dataset.nonce
                        } )
                    } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( data ) {
                        if ( data.success ) {
                            location.reload();
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Update ' + btn.dataset.version + ' durchführen';
                            msg.style.color = '#d63638';
                            msg.textContent = 'Fehler: ' + ( data.data || 'Unbekannt' );
                        }
                    } )
                    .catch( function () {
                        btn.disabled    = false;
                        btn.textContent = 'Update ' + btn.dataset.version + ' durchführen';
                        msg.style.color = '#d63638';
                        msg.textContent = 'Verbindungsfehler.';
                    } );
                } );
            }
        }());

        document.querySelectorAll( '.fgr-install-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var self = this;
                self.disabled    = true;
                self.textContent = 'Installiere…';
                fetch( ajaxurl, {
                    method: 'POST',
                    body:   new URLSearchParams( {
                        action:      'fgr_install_plugin',
                        slug:        self.dataset.slug,
                        _ajax_nonce: '<?php echo wp_create_nonce( 'fgr_install_plugin' ); ?>'
                    } )
                } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data.success ) {
                        location.reload();
                    } else {
                        alert( 'Fehler: ' + ( data.data || 'Unbekannter Fehler' ) );
                        self.disabled    = false;
                        self.textContent = 'Installieren';
                    }
                } )
                .catch( function () {
                    alert( 'Verbindungsfehler.' );
                    self.disabled    = false;
                    self.textContent = 'Installieren';
                } );
            } );
        } );
        </script>
        <?php
    }

    add_action( 'wp_ajax_fgr_install_plugin', 'fgr_install_plugin_handler' );

    function fgr_install_plugin_handler(): void {
        check_ajax_referer( 'fgr_install_plugin' );

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error( 'Keine Berechtigung.' );
        }

        $slug    = sanitize_key( $_POST['slug'] ?? '' );
        $allowed = [ 'fgr-mail-smtp', 'fgr-hide-login', 'fgr-maintenance', 'fgr-email-encoder' ];

        if ( ! in_array( $slug, $allowed, true ) ) {
            wp_send_json_error( 'Unbekanntes Plugin.' );
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $zip_url  = "https://github.com/FreieGestalterischeRepublik/{$slug}/archive/refs/heads/main.zip";
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $zip_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        if ( false === $result ) {
            wp_send_json_error( 'Installation fehlgeschlagen. Bitte Dateisystem-Berechtigungen prüfen.' );
        }

        $wrong_dir   = WP_PLUGIN_DIR . '/' . $slug . '-main';
        $correct_dir = WP_PLUGIN_DIR . '/' . $slug;
        if ( is_dir( $wrong_dir ) && ! is_dir( $correct_dir ) ) {
            rename( $wrong_dir, $correct_dir );
        }

        fgr_mu_sync();

        wp_send_json_success( [ 'message' => 'Plugin erfolgreich installiert.' ] );
    }
}
