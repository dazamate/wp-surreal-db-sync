<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Enum\MetaKeys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clickable Surreal ID in the publish box opens a modal with the live record payload.
 */
class SurrealDataPreviewManager {
    private const AJAX_ACTION = 'surreal_preview_record';
    private const NONCE_ACTION = 'surreal_preview_record';

    public static function load_hooks(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_footer', [ __CLASS__, 'render_modal' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_preview_record' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        $post    = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! ( $post instanceof \WP_Post ) ) {
            return;
        }

        $table = apply_filters( 'surreal_map_post_table_name', '', $post->post_type, $post_id );
        if ( $table === '' ) {
            return;
        }

        $surreal_id = (string) get_post_meta( $post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true );
        $payload    = null;
        $error      = null;

        if ( $surreal_id !== '' && self::is_safe_record_id( $surreal_id ) ) {
            try {
                $payload = self::load_payload( $surreal_id );
            } catch ( \Throwable $e ) {
                $error = $e->getMessage();
            }
        } elseif ( $surreal_id === '' ) {
            $error = 'Not synced — no surreal_id on this post';
        } else {
            $error = 'Invalid surreal_id format';
        }

        $handle = 'surreal-data-preview';
        wp_register_style( $handle, false, [], '1.0.1' );
        wp_enqueue_style( $handle );
        wp_add_inline_style( $handle, self::css() );

        wp_register_script( $handle, false, [], '1.0.1', true );
        wp_enqueue_script( $handle );
        wp_add_inline_script( $handle, self::js() );
        wp_localize_script(
            $handle,
            'surrealDataPreview',
            [
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'action'    => self::AJAX_ACTION,
                'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
                'postId'    => $post_id,
                'recordId'  => $surreal_id,
                'payload'   => $payload,
                'error'     => $error,
            ]
        );
    }

    public static function render_modal(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) {
            return;
        }

        $table = apply_filters( 'surreal_map_post_table_name', '', $post->post_type, $post_id );
        if ( $table === '' ) {
            return;
        }

        ?>
        <div id="surreal-preview-modal" class="surreal-modal" hidden aria-hidden="true">
            <div class="surreal-modal__backdrop" data-surreal-close></div>
            <div class="surreal-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="surreal-preview-title">
                <div class="surreal-modal__header">
                    <h2 id="surreal-preview-title">Surreal data</h2>
                    <button type="button" class="surreal-modal__close" data-surreal-close aria-label="Close">&times;</button>
                </div>
                <div class="surreal-modal__toolbar">
                    <code id="surreal-preview-record-id" class="surreal-modal__record-id"></code>
                    <button type="button" class="button button-small" id="surreal-preview-refresh">Refresh</button>
                </div>
                <div id="surreal-preview-status" class="surreal-modal__status" hidden></div>
                <pre id="surreal-preview-json" class="surreal-modal__json"></pre>
            </div>
        </div>
        <?php
    }

    public static function ajax_preview_record(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) {
            wp_send_json_error( [ 'message' => 'Post not found' ], 404 );
        }

        $surreal_id = (string) get_post_meta( $post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true );
        if ( $surreal_id === '' || ! self::is_safe_record_id( $surreal_id ) ) {
            wp_send_json_error( [ 'message' => 'No valid surreal_id on this post' ], 400 );
        }

        try {
            $payload = self::load_payload( $surreal_id );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }

        wp_send_json_success( $payload );
    }

    /**
     * @return array{record_id: string, record: mixed, graph: mixed}
     */
    private static function load_payload( string $surreal_id ): array {
        $db = apply_filters( 'get_surreal_db_conn', null );
        if ( ! is_object( $db ) || ! method_exists( $db, 'query' ) ) {
            throw new \RuntimeException( 'Surreal DB not connected' );
        }

        return self::fetch_record( $db, $surreal_id );
    }

    /**
     * @param object{query: callable} $db
     * @return array{record_id: string, record: mixed, graph: mixed}
     */
    private static function fetch_record( object $db, string $surreal_id ): array {
        $record_res = $db->query( sprintf( 'SELECT * FROM ONLY %s;', $surreal_id ) );
        $record     = is_array( $record_res ) ? ( $record_res[0] ?? null ) : null;

        $graph_res = $db->query(
            sprintf(
                'SELECT ->?.* AS outgoing, <-?.* AS incoming FROM ONLY %s;',
                $surreal_id
            )
        );

        return [
            'record_id' => $surreal_id,
            'record'    => $record,
            'graph'     => is_array( $graph_res ) ? ( $graph_res[0] ?? null ) : null,
        ];
    }

    private static function is_safe_record_id( string $id ): bool {
        return (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_]*:[A-Za-z0-9_-]+$/', $id );
    }

    private static function css(): string {
        return <<<'CSS'
.misc-pub-db-id-info .surreal-id-link {
    font-weight: 600;
    text-decoration: underline;
    word-break: break-all;
}
.surreal-modal[hidden] { display: none !important; }
.surreal-modal {
    position: fixed;
    inset: 0;
    z-index: 100100;
    display: flex;
    align-items: center;
    justify-content: center;
}
.surreal-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
}
.surreal-modal__dialog {
    position: relative;
    z-index: 1;
    width: min(920px, calc(100vw - 32px));
    max-height: calc(100vh - 48px);
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
}
.surreal-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #dcdcde;
}
.surreal-modal__header h2 {
    margin: 0;
    font-size: 16px;
    line-height: 1.3;
}
.surreal-modal__close {
    border: 0;
    background: transparent;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    color: #50575e;
    padding: 0 4px;
}
.surreal-modal__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid #f0f0f1;
}
.surreal-modal__record-id {
    font-size: 12px;
    word-break: break-all;
}
.surreal-modal__status {
    margin: 0;
    padding: 8px 16px;
    border-left: 3px solid #d63638;
    background: #fcf0f1;
    font-size: 13px;
}
.surreal-modal__status.is-ok {
    border-left-color: #00a32a;
    background: #edfaef;
}
.surreal-modal__status.is-loading {
    border-left-color: #2271b1;
    background: #f0f6fc;
}
.surreal-modal__json {
    margin: 0;
    padding: 14px 16px;
    overflow: auto;
    flex: 1 1 auto;
    min-height: 200px;
    max-height: calc(100vh - 180px);
    background: #1e1e1e;
    color: #d4d4d4;
    font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    white-space: pre-wrap;
    word-break: break-word;
    border-radius: 0 0 4px 4px;
}
.surreal-modal__json .j-key { color: #9cdcfe; }
.surreal-modal__json .j-str { color: #ce9178; }
.surreal-modal__json .j-num { color: #b5cea8; }
.surreal-modal__json .j-bool { color: #569cd6; }
.surreal-modal__json .j-null { color: #569cd6; font-style: italic; }
.surreal-modal__json .j-punc { color: #d4d4d4; }
CSS;
    }

    private static function js(): string {
        return <<<'JS'
(function () {
    const cfg = window.surrealDataPreview;
    if (!cfg) return;

    const modal = document.getElementById('surreal-preview-modal');
    const jsonEl = document.getElementById('surreal-preview-json');
    const statusEl = document.getElementById('surreal-preview-status');
    const idEl = document.getElementById('surreal-preview-record-id');
    const refreshBtn = document.getElementById('surreal-preview-refresh');
    if (!modal || !jsonEl || !statusEl || !idEl) return;

    let payload = cfg.payload || null;
    let error = cfg.error || null;

    function setStatus(msg, kind) {
        statusEl.hidden = !msg;
        statusEl.textContent = msg || '';
        statusEl.classList.remove('is-ok', 'is-loading');
        if (kind) statusEl.classList.add(kind);
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /** Tiny JSON highlighter — no external lib. */
    function highlightJson(value) {
        const json = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        const re = /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(?:\s*:)?|\btrue\b|\bfalse\b|\bnull\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?|[{}\[\],])/g;
        let out = '';
        let last = 0;
        let m;
        while ((m = re.exec(json)) !== null) {
            out += esc(json.slice(last, m.index));
            const tok = m[0];
            let cls = 'j-punc';
            if (tok[0] === '"') {
                cls = /:\s*$/.test(tok) ? 'j-key' : 'j-str';
            } else if (tok === 'true' || tok === 'false') {
                cls = 'j-bool';
            } else if (tok === 'null') {
                cls = 'j-null';
            } else if (/^-?\d/.test(tok)) {
                cls = 'j-num';
            }
            out += '<span class="' + cls + '">' + esc(tok) + '</span>';
            last = re.lastIndex;
        }
        out += esc(json.slice(last));
        return out;
    }

    function showPayload(data) {
        jsonEl.innerHTML = highlightJson(data);
    }

    function render() {
        idEl.textContent = cfg.recordId || '—';
        if (error) {
            setStatus(error, null);
            jsonEl.textContent = '';
            return;
        }
        if (!payload) {
            setStatus('No payload loaded', null);
            jsonEl.textContent = '';
            return;
        }
        setStatus('', null);
        showPayload(payload);
    }

    function openModal() {
        render();
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('surreal-modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('surreal-modal-open');
    }

    function refresh() {
        setStatus('Refreshing…', 'is-loading');
        const body = new FormData();
        body.append('action', cfg.action);
        body.append('nonce', cfg.nonce);
        body.append('post_id', String(cfg.postId));

        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    error = (res && res.data && res.data.message) ? res.data.message : 'Request failed';
                    payload = null;
                    render();
                    return;
                }
                error = null;
                payload = res.data;
                cfg.payload = payload;
                setStatus('Refreshed', 'is-ok');
                showPayload(payload);
            })
            .catch(function (err) {
                error = err && err.message ? err.message : 'Network error';
                payload = null;
                render();
            });
    }

    document.addEventListener('click', function (e) {
        const openLink = e.target.closest('[data-surreal-preview-open]');
        if (openLink) {
            e.preventDefault();
            openModal();
            return;
        }
        if (e.target.closest('[data-surreal-close]')) {
            e.preventDefault();
            closeModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true;
            refresh().finally(function () {
                refreshBtn.disabled = false;
            });
        });
    }
})();
JS;
    }
}
