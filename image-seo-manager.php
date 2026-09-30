<?php
/**
 * Plugin Name: Image SEO Manager
 * Description: Export image file names, alt text and titles from the Media Library to CSV, and bulk-update alt text and titles by file name from a CSV or pasted spreadsheet data.
 * Version:     1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License:     GPL-2.0-or-later
 * Text Domain: image-seo-manager
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. Everything lives here so nothing pollutes the global namespace.
 */
final class ISEOM_Plugin {

	const PAGE_SLUG  = 'iseom-image-seo';
	const CAPABILITY = 'upload_files';
	const PER_PAGE   = 50;    // Rows per page in the export table.
	const BATCH_SIZE = 100;   // Attachments processed per AJAX batch when applying changes.
	const JOB_TTL    = 7200;  // Seconds a preview/apply job is kept (transient).
	const CSV_CHUNK  = 1000;  // Rows fetched per DB query while streaming the CSV export.

	/** Recognised image extensions (used when splitting "name.ext"). */
	const EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'jfif', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'tif', 'tiff', 'ico', 'heic', 'heif' );

	/** @var ISEOM_Plugin|null */
	private static $instance = null;

	/** @var string Hook suffix of our admin page. */
	private $hook_suffix = '';

	/**
	 * Singleton bootstrap.
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_iseom_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_iseom_log', array( $this, 'handle_log_download' ) );
		add_action( 'wp_ajax_iseom_apply_batch', array( $this, 'ajax_apply_batch' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu + assets
	 * ------------------------------------------------------------------ */

	/** Add "Image SEO Manager" under Media. */
	public function register_menu() {
		$this->hook_suffix = add_media_page(
			'Image SEO Manager',
			'Image SEO Manager',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** Register inline CSS/JS (no external files needed) on our page only. */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_register_style( 'iseom-admin', false, array(), '1.0.0' );
		wp_enqueue_style( 'iseom-admin' );
		wp_add_inline_style( 'iseom-admin', $this->get_css() );

		wp_register_script( 'iseom-admin', false, array(), '1.0.0', true );
		wp_enqueue_script( 'iseom-admin' );
		wp_add_inline_script( 'iseom-admin', $this->get_js() );
	}

	private function get_css() {
		return '
.iseom-thumb{width:50px;height:50px;object-fit:cover;border-radius:3px;background:#f0f0f1;display:block;margin-bottom:2px}
.iseom-table td,.iseom-table th{vertical-align:middle}
.iseom-missing{color:#b32d2e;font-style:italic}
.iseom-muted{color:#787c82}
.iseom-old{color:#787c82;text-decoration:line-through}
.iseom-new{color:#1d2327}
.iseom-summary{display:flex;flex-wrap:wrap;gap:10px;margin:15px 0}
.iseom-count{padding:8px 14px;border-radius:4px;background:#f6f7f7;border:1px solid #dcdcde}
.iseom-count strong{font-size:18px;display:block}
.iseom-st{display:inline-block;padding:2px 8px;border-radius:3px;font-weight:600;white-space:nowrap}
.iseom-st-update{background:#e6f6ea;color:#0a7d2c}
.iseom-st-nochange{background:#f0f0f1;color:#50575e}
.iseom-st-notfound{background:#fbe9e9;color:#b32d2e}
.iseom-st-multiple{background:#fff1dc;color:#b45f06}
.iseom-st-error{background:#fbe9e9;color:#b32d2e;border:1px solid #b32d2e}
.iseom-box{background:#fff;border:1px solid #c3c4c7;padding:12px 20px;margin:15px 0;max-width:900px}
.iseom-box textarea{width:100%;font-family:monospace}
.iseom-progress{display:none;margin:10px 0;font-weight:600}
.iseom-note{font-size:12px;color:#787c82;display:block}
';
	}

	private function get_js() {
		// Drives the batched "Apply Changes" run: repeatedly asks the server to process the next 100 rows.
		return <<<'JS'
(function () {
	var cfg = window.iseomBulk;
	var btn = document.getElementById('iseom-apply');
	if (!cfg || !btn) { return; }
	var progress = document.getElementById('iseom-progress');
	var logLink = document.getElementById('iseom-log-link');
	var statuses = ['update', 'nochange', 'notfound', 'multiple', 'error'];

	function recount() {
		statuses.forEach(function (s) {
			var n = document.querySelectorAll('td.iseom-status[data-status="' + s + '"]').length;
			var el = document.querySelector('.iseom-count[data-status="' + s + '"] strong');
			if (el) { el.textContent = n; }
		});
	}

	function setRow(r) {
		var cell = document.querySelector('td.iseom-status[data-row="' + r.n + '"]');
		if (!cell) { return; }
		cell.setAttribute('data-status', r.status);
		var span = cell.querySelector('span');
		span.className = 'iseom-st iseom-st-' + r.status;
		span.textContent = r.label;
	}

	function runBatch() {
		var body = new FormData();
		body.append('action', 'iseom_apply_batch');
		body.append('nonce', cfg.nonce);
		body.append('token', cfg.token);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					throw new Error(res && res.data && res.data.message ? res.data.message : 'Unknown error');
				}
				res.data.results.forEach(setRow);
				recount();
				progress.textContent = cfg.i18n.processed + ' ' + res.data.processed + ' / ' + res.data.total;
				if (res.data.done) {
					progress.textContent = cfg.i18n.finished;
					var upd = document.querySelector('.iseom-count[data-status="update"] span');
					if (upd) { upd.textContent = cfg.i18n.updated; }
					logLink.style.display = 'inline-block';
				} else {
					runBatch();
				}
			})
			.catch(function (e) {
				progress.textContent = cfg.i18n.failed + ' ' + e.message;
				btn.disabled = false;
			});
	}

	btn.addEventListener('click', function () {
		if (!window.confirm(cfg.i18n.confirm)) { return; }
		btn.disabled = true;
		progress.style.display = 'block';
		progress.textContent = cfg.i18n.starting;
		runBatch();
	});
})();
JS;
	}

	/* ---------------------------------------------------------------------
	 * Page shell + tabs
	 * ------------------------------------------------------------------ */

	/** Render the admin page. */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'image-seo-manager' ), 403 );
		}

		$tab = ( isset( $_GET['tab'] ) && 'bulk' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'bulk' : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.

		$base = admin_url( 'upload.php' );
		echo '<div class="wrap"><h1>Image SEO Manager</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">Export</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'export' ), $base ) ),
			'export' === $tab ? 'nav-tab-active' : ''
		);
		printf(
			'<a href="%s" class="nav-tab %s">Bulk Update</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'bulk' ), $base ) ),
			'bulk' === $tab ? 'nav-tab-active' : ''
		);
		echo '</nav>';

		if ( 'bulk' === $tab ) {
			$this->render_bulk_tab();
		} else {
			$this->render_export_tab();
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Shared DB helpers
	 * ------------------------------------------------------------------ */

	/** FROM/JOIN clause: attachments + file path meta + alt meta. */
	private function from_sql() {
		global $wpdb;
		return "FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pf ON ( pf.post_id = p.ID AND pf.meta_key = '_wp_attached_file' )
			LEFT JOIN {$wpdb->postmeta} pa ON ( pa.post_id = p.ID AND pa.meta_key = '_wp_attachment_image_alt' )";
	}

	/**
	 * Build the WHERE clause (with placeholders) and its arguments.
	 *
	 * @return array{0:string,1:array}
	 */
	private function where_sql( $search = '', $missing_only = false ) {
		global $wpdb;
		$where = "p.post_type = 'attachment' AND p.post_mime_type LIKE %s";
		$args  = array( 'image/%' );

		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND ( pf.meta_value LIKE %s OR pa.meta_value LIKE %s OR p.post_title LIKE %s )';
			array_push( $args, $like, $like, $like );
		}
		if ( $missing_only ) {
			$where .= " AND ( pa.meta_value IS NULL OR TRIM( pa.meta_value ) = '' )";
		}
		return array( $where, $args );
	}

	/** Base file name (with extension) from a stored _wp_attached_file value. */
	private function base_name( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$pos  = strrpos( $path, '/' );
		return false === $pos ? $path : substr( $path, $pos + 1 );
	}

	/* ---------------------------------------------------------------------
	 * TAB 1: Export
	 * ------------------------------------------------------------------ */

	private function render_export_tab() {
		global $wpdb;

		// Read-only filter form: it still carries a nonce, verified whenever filter params are present.
		if ( isset( $_GET['s'] ) || isset( $_GET['missing'] ) || isset( $_GET['paged'] ) ) {
			check_admin_referer( 'iseom_filter' );
		}
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$missing = ! empty( $_GET['missing'] );
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		list( $where, $args ) = $this->where_sql( $search, $missing );
		$from                 = $this->from_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from/$where are built from constants; values go through prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT p.ID ) $from WHERE $where", $args ) );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date, pf.meta_value AS file, pa.meta_value AS alt
				 $from WHERE $where GROUP BY p.ID ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) )
			)
		);
		// phpcs:enable

		if ( $rows ) {
			update_meta_cache( 'post', wp_list_pluck( $rows, 'ID' ) ); // One query for all thumbnails.
		}

		// --- Toolbar: filter form + export button.
		echo '<div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin:15px 0">';

		echo '<form method="get" action="' . esc_url( admin_url( 'upload.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="export">';
		wp_nonce_field( 'iseom_filter', '_wpnonce', false );
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search file name, alt text or title"> ';
		echo '<label><input type="checkbox" name="missing" value="1" ' . checked( $missing, true, false ) . '> Missing alt text only</label> ';
		echo '<button class="button">Filter</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="iseom_export">';
		wp_nonce_field( 'iseom_export' );
		echo '<button class="button button-primary">Export to CSV (all images)</button>';
		echo '</form>';

		echo '</div>';
		echo '<p>' . esc_html( number_format_i18n( $total ) ) . ' image(s) found.</p>';

		// --- Table.
		echo '<table class="widefat striped iseom-table"><thead><tr>';
		echo '<th style="width:60px">Thumbnail</th><th>File Name</th><th>Alt Text</th><th>Image Title</th><th style="width:70px">ID</th><th style="width:110px">Uploaded</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="6">No images found.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$id    = (int) $r->ID;
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
			echo '<tr>';
			echo '<td>' . ( $thumb ? '<img class="iseom-thumb" loading="lazy" src="' . esc_url( $thumb ) . '" alt="">' : '' ) . '</td>';
			echo '<td>' . esc_html( $this->base_name( $r->file ) ) . '</td>';
			echo '<td>' . ( '' === trim( (string) $r->alt ) ? '<span class="iseom-missing">missing</span>' : esc_html( $r->alt ) ) . '</td>';
			echo '<td>' . esc_html( $r->post_title ) . '</td>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $id ) . '</a></td>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ), $r->post_date ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// --- Pagination.
		if ( $pages > 1 ) {
			$base = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'tab'      => 'export',
					's'        => $search,
					'missing'  => $missing ? 1 : 0,
					'_wpnonce' => wp_create_nonce( 'iseom_filter' ),
				),
				admin_url( 'upload.php' )
			);
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base . '%_%',
						'format'    => '&paged=%#%',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * admin-post.php handler: stream ALL images as a CSV download (nothing is written to disk).
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'image-seo-manager' ), 403 );
		}
		check_admin_referer( 'iseom_export' );

		global $wpdb;
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		while ( ob_get_level() ) {
			ob_end_clean(); // Make sure no earlier output corrupts the file.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="image-seo-export-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel / Sheets.
		fputcsv( $out, array( 'File Name', 'Alt Text', 'Image Title', 'Attachment ID' ), ',', '"', '' );

		list( $where, $args ) = $this->where_sql();
		$from                 = $this->from_sql();
		$last_id              = 0;

		// Walk the library in ID order, CSV_CHUNK rows at a time, to keep memory flat.
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, pf.meta_value AS file, pa.meta_value AS alt
					 $from WHERE $where AND p.ID > %d GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d",
					array_merge( $args, array( $last_id, self::CSV_CHUNK ) )
				)
			);
			foreach ( $rows as $r ) {
				fputcsv( $out, array( $this->base_name( $r->file ), (string) $r->alt, $r->post_title, (int) $r->ID ), ',', '"', '' );
				$last_id = (int) $r->ID;
			}
			flush();
		} while ( count( $rows ) === self::CSV_CHUNK );

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * TAB 2: Bulk update
	 * ------------------------------------------------------------------ */

	private function render_bulk_tab() {
		$opts    = array( 'overwrite' => false, 'multi' => 'all', 'paste' => '' );
		$errors  = array();
		$preview = null;
		$token   = '';
		$job     = null;

		// Preview form submitted (dry run: nothing is changed).
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['iseom_preview'] ) ) {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'image-seo-manager' ), 403 );
			}
			check_admin_referer( 'iseom_preview' );

			$opts['overwrite'] = ! empty( $_POST['iseom_overwrite'] );
			$opts['multi']     = ( isset( $_POST['iseom_multi'] ) && 'skip' === sanitize_key( wp_unslash( $_POST['iseom_multi'] ) ) ) ? 'skip' : 'all';
			// Raw pasted text must keep its tabs/newlines to be parsed; every cell is sanitised after parsing.
			$opts['paste'] = isset( $_POST['iseom_paste'] ) ? wp_unslash( $_POST['iseom_paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$raw = $this->read_input( $opts['paste'], $errors );
			if ( empty( $errors ) ) {
				$parsed = $this->parse_delimited( $raw );
				if ( is_wp_error( $parsed ) ) {
					$errors[] = $parsed->get_error_message();
				} elseif ( ! $parsed ) {
					$errors[] = 'No data rows were found below the header row.';
				} else {
					list( $job, $preview ) = $this->build_job( $parsed, $opts );
					$token                 = wp_generate_password( 24, false );
					set_transient( 'iseom_job_' . $token, $job, self::JOB_TTL );
				}
			}
		}

		// --- Input form.
		foreach ( $errors as $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e ) . '</p></div>';
		}

		echo '<div class="iseom-box">';
		echo '<h2>Bulk update by file name</h2>';
		echo '<p>Upload a CSV/TXT file <strong>or</strong> paste tab-separated rows copied from Google Sheets / Excel. The first row must be a header containing <code>File Name</code>, <code>Alt Text</code> and/or <code>Image Title</code> (any order; other columns such as Attachment ID are ignored). Empty cells leave the existing value untouched.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'bulk' ), admin_url( 'upload.php' ) ) ) . '">';
		wp_nonce_field( 'iseom_preview' );
		echo '<p><label><strong>CSV file (.csv / .txt)</strong><br><input type="file" name="iseom_file" accept=".csv,.txt"></label></p>';
		echo '<p><label><strong>&hellip;or paste data here</strong><br><textarea name="iseom_paste" rows="8" placeholder="File Name&#9;Alt Text&#9;Image Title">' . esc_textarea( $opts['paste'] ) . '</textarea></label></p>';
		echo '<p><label><input type="checkbox" name="iseom_overwrite" value="1" ' . checked( $opts['overwrite'], true, false ) . '> Overwrite existing values</label>';
		echo '<span class="iseom-note">Unchecked: only empty fields are filled. Note WordPress often sets the title to the file name on upload, so titles are usually not &ldquo;empty&rdquo;.</span></p>';
		echo '<p><label>If a file name matches multiple images: <select name="iseom_multi">';
		echo '<option value="all" ' . selected( $opts['multi'], 'all', false ) . '>Update all</option>';
		echo '<option value="skip" ' . selected( $opts['multi'], 'skip', false ) . '>Skip and flag</option>';
		echo '</select></label></p>';
		echo '<p><button type="submit" name="iseom_preview" value="1" class="button button-primary">Preview</button> <span class="iseom-muted">Nothing is changed until you click Apply Changes.</span></p>';
		echo '</form></div>';

		if ( $preview ) {
			$this->render_preview( $preview, $token );
		}
	}

	/**
	 * Get the raw text to parse: the uploaded file if provided, otherwise the pasted text.
	 *
	 * @param string $paste  Pasted text.
	 * @param array  $errors Error messages (by reference).
	 * @return string
	 */
	private function read_input( $paste, array &$errors ) {
		$has_file = isset( $_FILES['iseom_file'] ) && isset( $_FILES['iseom_file']['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['iseom_file']['error'];

		if ( $has_file ) {
			$file = $_FILES['iseom_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$errors[] = 'The file could not be uploaded (error code ' . (int) $file['error'] . ').';
				return '';
			}
			$ext = strtolower( pathinfo( sanitize_file_name( wp_unslash( $file['name'] ) ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
				$errors[] = 'Invalid file type. Please upload a .csv or .txt file.';
				return '';
			}
			if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
				$errors[] = 'Upload validation failed.';
				return '';
			}
			$raw = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== strpos( $raw, "\0" ) ) {
				$errors[] = 'The file looks binary, not text. Please upload a plain CSV.';
				return '';
			}
			return $raw;
		}

		if ( '' === trim( $paste ) ) {
			$errors[] = 'Please upload a CSV file or paste your data.';
		}
		return $paste;
	}

	/**
	 * Parse CSV/TSV text into rows: array of array( n, file, alt, title ).
	 *
	 * @param string $raw Raw text.
	 * @return array|WP_Error
	 */
	private function parse_delimited( $raw ) {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );

		// Convert non-UTF-8 files (e.g. Excel "CSV" in Windows-1252) to UTF-8.
		if ( '' !== $raw && '' === wp_check_invalid_utf8( $raw ) ) {
			$conv = function_exists( 'iconv' ) ? @iconv( 'Windows-1252', 'UTF-8//IGNORE', $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$raw  = false !== $conv ? $conv : wp_check_invalid_utf8( $raw, true );
		}
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		// Guess delimiter from the header line: tab (pasted from Sheets), semicolon or comma.
		$first = strtok( $raw, "\n" );
		$first = false === $first ? '' : $first;
		$count = array(
			"\t" => substr_count( $first, "\t" ),
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
		);
		arsort( $count );
		$delim = key( $count );
		if ( 0 === reset( $count ) ) {
			$delim = ',';
		}

		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, $raw );
		rewind( $h );

		$map  = null;
		$rows = array();
		$n    = 0;

		while ( false !== ( $cells = fgetcsv( $h, 0, $delim, '"', '' ) ) ) {
			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}

			// First non-blank row = header. Match column names case-insensitively, ignoring spaces/underscores.
			if ( null === $map ) {
				$map     = array( 'file' => null, 'alt' => null, 'title' => null );
				$aliases = array(
					'file'  => array( 'filename', 'file', 'imagefile', 'imagefilename' ),
					'alt'   => array( 'alttext', 'alt', 'alternativetext', 'imagealttext' ),
					'title' => array( 'imagetitle', 'title', 'posttitle' ),
				);
				foreach ( $cells as $i => $name ) {
					$key = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
					foreach ( $aliases as $field => $list ) {
						if ( null === $map[ $field ] && in_array( $key, $list, true ) ) {
							$map[ $field ] = $i;
						}
					}
				}
				if ( null === $map['file'] ) {
					fclose( $h );
					return new WP_Error( 'iseom_header', 'Header row must contain a "File Name" column.' );
				}
				if ( null === $map['alt'] && null === $map['title'] ) {
					fclose( $h );
					return new WP_Error( 'iseom_header', 'Header row must contain an "Alt Text" and/or "Image Title" column.' );
				}
				continue;
			}

			$n++;
			$get    = function ( $field ) use ( $map, $cells ) {
				return ( null !== $map[ $field ] && isset( $cells[ $map[ $field ] ] ) ) ? sanitize_text_field( (string) $cells[ $map[ $field ] ] ) : '';
			};
			$rows[] = array(
				'n'     => $n,
				'file'  => $get( 'file' ),
				'alt'   => $get( 'alt' ),
				'title' => $get( 'title' ),
			);
		}
		fclose( $h );

		if ( null === $map ) {
			return new WP_Error( 'iseom_header', 'No header row found.' );
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Matching engine
	 * ------------------------------------------------------------------ */

	/** Lower-case, multibyte-safe when available. */
	private function lc( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Split a file name (or path) into array( stem, ext ), lower-cased, folders removed.
	 * The extension is only split off when it is a known image extension.
	 */
	private function split_name( $name ) {
		$base = $this->lc( trim( $this->base_name( $name ) ) );
		if ( preg_match( '/^(.+)\.([a-z0-9]{2,5})$/', $base, $m ) && in_array( $m[2], self::EXTENSIONS, true ) ) {
			return array( $m[1], $m[2] );
		}
		return array( $base, '' );
	}

	/**
	 * Progressively strip WordPress-style suffixes: photo-1-scaled => photo-1 => photo.
	 * Handles -scaled, -rotated, -WxH, -N and -eTIMESTAMP.
	 */
	private function stem_variants( $stem ) {
		$out = array();
		while ( preg_match( '/^(.+)(?:-scaled|-rotated|-\d+x\d+|-e\d{10,}|-\d+)$/', $stem, $m ) ) {
			$stem  = $m[1];
			$out[] = $stem;
		}
		return $out;
	}

	/**
	 * Load every image once and index it by stem for fast lookups.
	 *
	 * @return array{items:array,exact:array,stripped:array}
	 */
	private function build_library_index() {
		global $wpdb;
		$from = $this->from_sql();
		list( $where, $args ) = $this->where_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, pf.meta_value AS file, pa.meta_value AS alt $from WHERE $where GROUP BY p.ID", $args ) );
		$index = array( 'items' => array(), 'exact' => array(), 'stripped' => array() );

		foreach ( $rows as $r ) {
			$id                  = (int) $r->ID;
			list( $stem, $ext )  = $this->split_name( (string) $r->file );
			$index['items'][ $id ] = array(
				'alt'   => (string) $r->alt,
				'title' => (string) $r->post_title,
				'ext'   => $ext,
			);
			$index['exact'][ $stem ][] = $id;
			foreach ( $this->stem_variants( $stem ) as $v ) {
				$index['stripped'][ $v ][] = $id;
			}
		}
		return $index;
	}

	/**
	 * Find attachment IDs for a CSV file name.
	 * Tier 1: exact stem match. Tier 2: match after stripping WP suffixes from the library name.
	 * If the CSV name has an extension it must equal the library extension.
	 *
	 * @return int[]
	 */
	private function find_matches( $name, array $index ) {
		list( $stem, $ext ) = $this->split_name( $name );
		if ( '' === $stem ) {
			return array();
		}
		foreach ( array( 'exact', 'stripped' ) as $tier ) {
			if ( empty( $index[ $tier ][ $stem ] ) ) {
				continue;
			}
			$ids = array();
			foreach ( array_unique( $index[ $tier ][ $stem ] ) as $id ) {
				if ( '' === $ext || $index['items'][ $id ]['ext'] === $ext ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				return $ids;
			}
		}
		return array();
	}

	/**
	 * Decide what would change for one attachment. Returns null for "leave unchanged".
	 *
	 * @return array{alt:?string,title:?string}
	 */
	private function plan_change( $cur_alt, $cur_title, array $row, $overwrite ) {
		$out = array( 'alt' => null, 'title' => null );
		if ( '' !== $row['alt'] && $row['alt'] !== $cur_alt && ( $overwrite || '' === trim( $cur_alt ) ) ) {
			$out['alt'] = $row['alt'];
		}
		if ( '' !== $row['title'] && $row['title'] !== $cur_title && ( $overwrite || '' === trim( $cur_title ) ) ) {
			$out['title'] = $row['title'];
		}
		return $out;
	}

	/**
	 * Dry run: match every row and build (a) the job stored for the apply step and (b) the preview data.
	 *
	 * @return array array( $job, $preview )
	 */
	private function build_job( array $parsed, array $opts ) {
		$index   = $this->build_library_index();
		$rows    = array();
		$preview = array();

		foreach ( $parsed as $row ) {
			$entry = array( 'n' => $row['n'], 'file' => $row['file'], 'alt' => $row['alt'], 'title' => $row['title'], 'ids' => array(), 'pre' => '' );
			$prev  = array( 'n' => $row['n'], 'file' => $row['file'], 'status' => 'nochange', 'matches' => array() );

			if ( '' === $row['file'] ) {
				$entry['pre']   = $prev['status'] = 'error';
			} else {
				$ids = $this->find_matches( $row['file'], $index );
				if ( ! $ids ) {
					$entry['pre'] = $prev['status'] = 'notfound';
				} else {
					$changed = false;
					foreach ( $ids as $id ) {
						$cur = $index['items'][ $id ];
						$chg = $this->plan_change( $cur['alt'], $cur['title'], $row, $opts['overwrite'] );
						if ( null !== $chg['alt'] || null !== $chg['title'] ) {
							$changed = true;
						}
						$prev['matches'][] = array( 'id' => $id, 'alt' => $cur['alt'], 'title' => $cur['title'], 'new_alt' => $chg['alt'], 'new_title' => $chg['title'] );
					}
					if ( count( $ids ) > 1 && 'skip' === $opts['multi'] ) {
						$entry['pre']   = $prev['status'] = 'multiple';
					} else {
						$entry['ids']   = $ids;
						$prev['status'] = $changed ? 'update' : 'nochange';
					}
				}
			}
			$rows[]    = $entry;
			$preview[] = $prev;
		}

		$job = array(
			'user'      => get_current_user_id(),
			'overwrite' => $opts['overwrite'],
			'rows'      => $rows,
			'log'       => array(),
			'next'      => 0,
		);
		return array( $job, $preview );
	}

	/* ---------------------------------------------------------------------
	 * Preview rendering
	 * ------------------------------------------------------------------ */

	/** Human-readable status labels. */
	private function status_labels() {
		return array(
			'update'   => 'Will update',
			'nochange' => 'No change needed',
			'notfound' => 'Not found',
			'multiple' => 'Multiple matches (skipped)',
			'error'    => 'Row error',
		);
	}

	/** "old -> new" HTML (already escaped). */
	private function fmt_change( $cur, $new ) {
		$cur_html = '' === $cur ? '<em>(empty)</em>' : esc_html( $cur );
		if ( null === $new ) {
			return '<span class="iseom-muted">' . $cur_html . '</span>';
		}
		return '<span class="iseom-old">' . $cur_html . '</span> &rarr; <strong class="iseom-new">' . esc_html( $new ) . '</strong>';
	}

	private function render_preview( array $preview, $token ) {
		$labels = $this->status_labels();
		$counts = array_fill_keys( array_keys( $labels ), 0 );
		$ids    = array();
		foreach ( $preview as $p ) {
			$counts[ $p['status'] ]++;
			foreach ( $p['matches'] as $m ) {
				$ids[] = $m['id'];
			}
		}
		if ( $ids ) {
			foreach ( array_chunk( array_unique( $ids ), 500 ) as $chunk ) {
				update_meta_cache( 'post', $chunk ); // Prime meta so thumbnails don't query one by one.
			}
		}

		echo '<h2>Preview (' . esc_html( count( $preview ) ) . ' rows) &mdash; no changes made yet</h2>';

		// Summary counts (updated live by the JS after applying).
		echo '<div class="iseom-summary">';
		foreach ( $labels as $status => $label ) {
			printf(
				'<div class="iseom-count" data-status="%1$s"><strong>%2$s</strong><span class="iseom-st iseom-st-%1$s">%3$s</span></div>',
				esc_attr( $status ),
				esc_html( $counts[ $status ] ),
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( $counts['update'] > 0 ) {
			echo '<p><button type="button" id="iseom-apply" class="button button-primary">Apply Changes</button> ';
			echo '<a id="iseom-log-link" class="button" style="display:none" href="' . esc_url(
				add_query_arg(
					array(
						'action'   => 'iseom_log',
						'token'    => $token,
						'_wpnonce' => wp_create_nonce( 'iseom_log' ),
					),
					admin_url( 'admin-post.php' )
				)
			) . '">Download results log (CSV)</a></p>';
			echo '<div id="iseom-progress" class="iseom-progress"></div>';

			wp_localize_script(
				'iseom-admin',
				'iseomBulk',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'iseom_apply' ),
					'token'   => $token,
					'i18n'    => array(
						'confirm'   => sprintf( 'Apply changes for %d row(s) to your Media Library? This cannot be undone automatically. Tip: export a CSV backup first.', $counts['update'] ),
						'starting'  => 'Starting...',
						'processed' => 'Processed rows:',
						'finished'  => 'Finished. You can now download the results log.',
						'updated'   => 'Updated',
						'failed'    => 'Stopped:',
					),
				)
			);
		} else {
			echo '<p><strong>Nothing to update.</strong></p>';
		}

		echo '<table class="widefat striped iseom-table"><thead><tr>';
		echo '<th style="width:50px">Row #</th><th>File Name</th><th style="width:70px">Thumbnail</th><th>Current Alt &rarr; New Alt</th><th>Current Title &rarr; New Title</th><th>Status</th>';
		echo '</tr></thead><tbody>';

		foreach ( $preview as $p ) {
			$alts   = array();
			$titles = array();
			$thumbs = '';
			foreach ( $p['matches'] as $m ) {
				$url = wp_get_attachment_image_url( $m['id'], 'thumbnail' );
				if ( $url ) {
					$thumbs .= '<img class="iseom-thumb" loading="lazy" src="' . esc_url( $url ) . '" alt="">';
				}
				$alts[]   = $this->fmt_change( $m['alt'], $m['new_alt'] );
				$titles[] = $this->fmt_change( $m['title'], $m['new_title'] );
			}
			$note = '';
			if ( 'error' === $p['status'] ) {
				$note = '<span class="iseom-note">Missing file name</span>';
			} elseif ( count( $p['matches'] ) > 1 ) {
				$note = '<span class="iseom-note">' . esc_html( count( $p['matches'] ) ) . ' matching images</span>';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $p['n'] ) . '</td>';
			echo '<td>' . esc_html( $p['file'] ) . '</td>';
			echo '<td>' . $thumbs . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_url() above.
			echo '<td>' . implode( '<hr>', $alts ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fmt_change() escapes.
			echo '<td>' . implode( '<hr>', $titles ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			printf(
				'<td class="iseom-status" data-row="%1$d" data-status="%2$s"><span class="iseom-st iseom-st-%2$s">%3$s</span>%4$s</td>',
				(int) $p['n'],
				esc_attr( $p['status'] ),
				esc_html( $labels[ $p['status'] ] ),
				$note // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Apply (batched via AJAX) + results log
	 * ------------------------------------------------------------------ */

	/** AJAX: apply the next BATCH_SIZE rows of a previewed job. */
	public function ajax_apply_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		check_ajax_referer( 'iseom_apply', 'nonce' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$job   = '' !== $token ? get_transient( 'iseom_job_' . $token ) : false;
		if ( ! $job || (int) $job['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'This preview has expired. Please run Preview again.' ) );
		}

		$total = count( $job['rows'] );
		if ( $job['next'] >= $total ) {
			wp_send_json_error( array( 'message' => 'These changes were already applied.' ) );
		}

		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$labels  = $this->status_labels();
		$labels['update'] = 'Updated';
		$results = array();
		$slice   = array_slice( $job['rows'], $job['next'], self::BATCH_SIZE );

		foreach ( $slice as $row ) {
			$log = $this->apply_row( $row, (bool) $job['overwrite'] );
			$job['log'][] = $log;
			$results[]    = array( 'n' => $row['n'], 'status' => $log['status'], 'label' => $labels[ $log['status'] ] );
		}

		$job['next'] += count( $slice );
		set_transient( 'iseom_job_' . $token, $job, self::JOB_TTL );

		wp_send_json_success(
			array(
				'results'   => $results,
				'processed' => $job['next'],
				'total'     => $total,
				'done'      => $job['next'] >= $total,
			)
		);
	}

	/**
	 * Really update one CSV row's matched attachments.
	 *
	 * @return array Log entry.
	 */
	private function apply_row( array $row, $overwrite ) {
		$log = array(
			'n'         => $row['n'],
			'file'      => $row['file'],
			'ids'       => implode( ' | ', $row['ids'] ),
			'status'    => $row['pre'] ? $row['pre'] : 'nochange',
			'old_alt'   => array(),
			'new_alt'   => array(),
			'old_title' => array(),
			'new_title' => array(),
			'note'      => 'error' === $row['pre'] ? 'Missing file name' : '',
		);
		if ( $row['pre'] ) {
			return $this->flatten_log( $log );
		}

		$updated = false;
		$failed  = false;
		foreach ( $row['ids'] as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$failed          = true;
				$log['note']    .= "ID $id not found. ";
				continue;
			}
			// Re-read current values so a stale preview can't overwrite newer edits when "overwrite" is off.
			$cur_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			$chg     = $this->plan_change( $cur_alt, $post->post_title, $row, $overwrite );

			if ( null !== $chg['alt'] ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $chg['alt'] ) );
				$log['old_alt'][] = $cur_alt;
				$log['new_alt'][] = $chg['alt'];
				$updated          = true;
			}
			if ( null !== $chg['title'] ) {
				$res = wp_update_post( array( 'ID' => $id, 'post_title' => wp_slash( $chg['title'] ) ), true );
				if ( is_wp_error( $res ) ) {
					$failed       = true;
					$log['note'] .= "ID $id: " . $res->get_error_message() . ' ';
				} else {
					$log['old_title'][] = $post->post_title;
					$log['new_title'][] = $chg['title'];
					$updated            = true;
				}
			}
		}

		$log['status'] = $failed ? 'error' : ( $updated ? 'update' : 'nochange' );
		return $this->flatten_log( $log );
	}

	/** Collapse array fields of a log entry to strings for CSV output. */
	private function flatten_log( array $log ) {
		foreach ( array( 'old_alt', 'new_alt', 'old_title', 'new_title' ) as $k ) {
			$log[ $k ] = implode( ' | ', (array) $log[ $k ] );
		}
		$log['note'] = trim( $log['note'] );
		return $log;
	}

	/** admin-post.php handler: stream the results log as CSV. */
	public function handle_log_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'image-seo-manager' ), 403 );
		}
		check_admin_referer( 'iseom_log' );

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$job   = '' !== $token ? get_transient( 'iseom_job_' . $token ) : false;
		if ( ! $job || (int) $job['user'] !== get_current_user_id() || empty( $job['log'] ) ) {
			wp_die( esc_html__( 'No results log is available (it may have expired).', 'image-seo-manager' ) );
		}

		$labels           = $this->status_labels();
		$labels['update'] = 'Updated';

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="image-seo-update-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Row #', 'File Name', 'Attachment ID(s)', 'Status', 'Old Alt', 'New Alt', 'Old Title', 'New Title', 'Notes' ), ',', '"', '' );
		foreach ( $job['log'] as $l ) {
			fputcsv(
				$out,
				array( $l['n'], $l['file'], $l['ids'], $labels[ $l['status'] ], $l['old_alt'], $l['new_alt'], $l['old_title'], $l['new_title'], $l['note'] ),
				',',
				'"',
				''
			);
		}
		fclose( $out );
		exit;
	}
}

ISEOM_Plugin::init();
