<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Admin_Page {

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'WP REST Importer', 'wp-rest-importer' ),
			__( 'WP REST Importer', 'wp-rest-importer' ),
			WPRestI_Settings::capability(),
			'wp-rest-importer',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_wp-rest-importer' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wpresti-admin',
			WPRESTI_URL . 'assets/admin.css',
			[],
			WPRESTI_VERSION
		);

		wp_enqueue_script(
			'wpresti-admin',
			WPRESTI_URL . 'assets/admin.js',
			[],
			WPRESTI_VERSION,
			true
		);

		wp_localize_script(
			'wpresti-admin',
			'wprestiData',
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpresti_nonce' ),
				'settings' => WPRestI_Settings::get_all(),
				'i18n'     => [
					'confirmCancel'   => __( 'Cancel the running import?', 'wp-rest-importer' ),
					'confirmClear'    => __( 'Clear the import session and queue?', 'wp-rest-importer' ),
					'testing'         => __( 'Testing…', 'wp-rest-importer' ),
					'testConnection'  => __( 'Test Connection', 'wp-rest-importer' ),
					'startImport'     => __( 'Start Import', 'wp-rest-importer' ),
					'importing'       => __( 'Importing…', 'wp-rest-importer' ),
					'summaryFull'     => __( 'Full import from remote site', 'wp-rest-importer' ),
					'summarySlug'     => __( 'Import by slug only', 'wp-rest-importer' ),
					'summaryTypeBoth' => __( 'Posts & pages', 'wp-rest-importer' ),
					'summaryTypePosts'=> __( 'Posts only', 'wp-rest-importer' ),
					'summaryTypePages'=> __( 'Pages only', 'wp-rest-importer' ),
					'logEmpty'        => __( 'Imported items will appear here as they are processed.', 'wp-rest-importer' ),
					'flowPlaceholder' => __( 'Enter source URL…', 'wp-rest-importer' ),
				],
			]
		);
	}

	public function render_page(): void {
		if ( ! WPRestI_Settings::current_user_can() ) {
			return;
		}

		$settings = WPRestI_Settings::get_all();
		?>
		<div class="wrap" id="wpresti-wrap">

			<h1 class="screen-reader-text"><?php esc_html_e( 'WP REST Importer', 'wp-rest-importer' ); ?></h1>

			<header class="pp-hero">
				<div class="pp-hero-main">
					<div class="pp-hero-icon" aria-hidden="true">
						<span class="dashicons dashicons-migrate"></span>
					</div>
					<div class="pp-hero-text">
						<h2 class="pp-hero-title"><?php esc_html_e( 'WP REST Importer', 'wp-rest-importer' ); ?></h2>
						<p class="pp-hero-subtitle"><?php esc_html_e( 'Pull posts and pages from any WordPress site via the REST API.', 'wp-rest-importer' ); ?></p>
					</div>
					<span class="pp-version-badge">v<?php echo esc_html( WPRESTI_VERSION ); ?></span>
				</div>
				<nav id="pp-tabs" class="pp-tab-pills" aria-label="<?php esc_attr_e( 'Plugin sections', 'wp-rest-importer' ); ?>">
					<a href="#" class="pp-tab-pill nav-tab-active" data-tab="import">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Import', 'wp-rest-importer' ); ?>
					</a>
					<a href="#" class="pp-tab-pill" data-tab="reassign">
						<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
						<?php esc_html_e( 'Authors', 'wp-rest-importer' ); ?>
					</a>
					<a href="#" class="pp-tab-pill" data-tab="settings">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
						<?php esc_html_e( 'Settings', 'wp-rest-importer' ); ?>
					</a>
				</nav>
				<div class="pp-hero-links">
					<a href="https://github.com/fysalyaqoob/wp-rest-importer#readme" target="_blank" rel="noopener" class="pp-hero-link"><?php esc_html_e( 'Documentation', 'wp-rest-importer' ); ?></a>
					<span class="pp-hero-link-sep" aria-hidden="true">·</span>
					<a href="https://github.com/fysalyaqoob/wp-rest-importer/issues" target="_blank" rel="noopener" class="pp-hero-link"><?php esc_html_e( 'Get support', 'wp-rest-importer' ); ?></a>
				</div>
			</header>

			<!-- ══════════════════════════════════════════════════════
			     Import tab
			     ══════════════════════════════════════════════════════ -->
			<div id="pp-tab-import" class="pp-tab-panel">

				<!-- Credentials info notice (dismissible) -->
				<div id="pp-creds-notice" class="pp-creds-notice">
					<span class="dashicons dashicons-info pp-creds-notice-icon"></span>
					<span class="pp-creds-notice-text"><?php esc_html_e( 'For best Gutenberg import quality, provide Application Password credentials in Advanced Settings. Without credentials, Gutenberg posts are imported as HTML blocks.', 'wp-rest-importer' ); ?></span>
					<button type="button" id="pp-creds-notice-dismiss" class="pp-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss notice', 'wp-rest-importer' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div class="pp-import-layout">

					<div class="pp-col-wizard">
						<form id="wpresti-form" class="pp-wizard-form">
							<?php wp_nonce_field( 'wpresti_nonce', 'wpresti_nonce_field' ); ?>

							<div class="pp-step-card pp-card">
								<div class="pp-step-head">
									<span class="pp-step-num">1</span>
									<div>
										<h3 class="pp-step-title"><?php esc_html_e( 'Connect to source', 'wp-rest-importer' ); ?></h3>
										<p class="pp-step-desc"><?php esc_html_e( 'The WordPress site you want to copy content from.', 'wp-rest-importer' ); ?></p>
									</div>
								</div>
								<div class="pp-step-body">
									<label for="pp-site-url" class="pp-label"><?php esc_html_e( 'Source site URL', 'wp-rest-importer' ); ?></label>
									<div class="pp-input-group">
										<span class="pp-input-prefix" aria-hidden="true">
											<span class="dashicons dashicons-admin-site-alt3"></span>
										</span>
										<input
											type="url"
											id="pp-site-url"
											name="pp_site_url"
											class="pp-input pp-input-mono pp-input-with-prefix"
											placeholder="https://example.com"
											required
											autocomplete="url"
										/>
									</div>
									<button type="button" id="pp-test-connection" class="pp-btn pp-btn-ghost pp-mt-8">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<?php esc_html_e( 'Test connection', 'wp-rest-importer' ); ?>
									</button>
									<div id="pp-test-result" class="pp-test-result" style="display:none;" role="status" aria-live="polite"></div>
								</div>
							</div>

							<div class="pp-step-card pp-card">
								<div class="pp-step-head">
									<span class="pp-step-num">2</span>
									<div>
										<h3 class="pp-step-title"><?php esc_html_e( 'Choose content', 'wp-rest-importer' ); ?></h3>
										<p class="pp-step-desc"><?php esc_html_e( 'How much to pull from the remote site.', 'wp-rest-importer' ); ?></p>
									</div>
								</div>
								<div class="pp-step-body">
									<p class="pp-label"><?php esc_html_e( 'Import mode', 'wp-rest-importer' ); ?></p>
									<div class="pp-scope-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Import mode', 'wp-rest-importer' ); ?>">
										<label class="pp-scope-card">
											<input type="radio" name="pp_import_scope" value="full" class="pp-method-input" checked />
											<span class="pp-scope-inner">
												<span class="pp-scope-icon dashicons dashicons-cloud-download" aria-hidden="true"></span>
												<span class="pp-scope-title"><?php esc_html_e( 'Everything', 'wp-rest-importer' ); ?></span>
												<span class="pp-scope-desc"><?php esc_html_e( 'All posts/pages matching your filters', 'wp-rest-importer' ); ?></span>
											</span>
										</label>
										<label class="pp-scope-card">
											<input type="radio" name="pp_import_scope" value="slug" class="pp-method-input" />
											<span class="pp-scope-inner">
												<span class="pp-scope-icon dashicons dashicons-tag" aria-hidden="true"></span>
												<span class="pp-scope-title"><?php esc_html_e( 'Specific URLs', 'wp-rest-importer' ); ?></span>
												<span class="pp-scope-desc"><?php esc_html_e( 'Only named slugs (e.g. one landing page)', 'wp-rest-importer' ); ?></span>
											</span>
										</label>
									</div>

									<div id="pp-slug-section" class="pp-slug-panel is-hidden">
										<label for="pp-slug" class="pp-label"><?php esc_html_e( 'Page/post slugs', 'wp-rest-importer' ); ?></label>
										<textarea
											id="pp-slug"
											name="pp_slug"
											class="pp-input pp-input-mono pp-textarea"
											rows="3"
											placeholder="<?php esc_attr_e( 'about-us', 'wp-rest-importer' ); ?>"
											aria-describedby="pp-slug-hint"
										></textarea>
										<p class="pp-hint" id="pp-slug-hint"><?php esc_html_e( 'One per line. Use the slug from the URL, not the full path.', 'wp-rest-importer' ); ?></p>
									</div>

									<p class="pp-label pp-mt-16"><?php esc_html_e( 'Remote content type', 'wp-rest-importer' ); ?></p>
									<div class="pp-segment" role="group" aria-label="<?php esc_attr_e( 'Remote content type', 'wp-rest-importer' ); ?>">
										<button type="button" class="pp-segment-btn is-active" data-type="both"><?php esc_html_e( 'Posts & Pages', 'wp-rest-importer' ); ?></button>
										<button type="button" class="pp-segment-btn" data-type="posts"><?php esc_html_e( 'Posts', 'wp-rest-importer' ); ?></button>
										<button type="button" class="pp-segment-btn" data-type="pages"><?php esc_html_e( 'Pages', 'wp-rest-importer' ); ?></button>
									</div>
									<select id="pp-import-type" name="pp_import_type" class="pp-select pp-visually-hidden" tabindex="-1" aria-hidden="true">
										<option value="both"><?php esc_html_e( 'Posts &amp; Pages', 'wp-rest-importer' ); ?></option>
										<option value="posts"><?php esc_html_e( 'Posts only', 'wp-rest-importer' ); ?></option>
										<option value="pages"><?php esc_html_e( 'Pages only', 'wp-rest-importer' ); ?></option>
									</select>

									<div class="pp-field pp-mt-16">
										<label for="pp-target-post-type" class="pp-label"><?php esc_html_e( 'Save on this site as', 'wp-rest-importer' ); ?></label>
										<select id="pp-target-post-type" name="pp_target_post_type" class="pp-select">
											<option value=""><?php esc_html_e( 'Keep same type (post → post, page → page)', 'wp-rest-importer' ); ?></option>
											<?php foreach ( self::get_importable_post_types() as $pt ) : ?>
												<option value="<?php echo esc_attr( $pt->name ); ?>">
													<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>

							<fieldset id="pp-fetch-limits" class="pp-filter-panel">
								<div class="pp-filter-panel-head">
									<span class="dashicons dashicons-filter" aria-hidden="true"></span>
									<div>
										<strong><?php esc_html_e( 'Filters', 'wp-rest-importer' ); ?></strong>
										<span class="pp-filter-badge"><?php esc_html_e( 'Full import only', 'wp-rest-importer' ); ?></span>
										<p class="pp-hint"><?php esc_html_e( 'Narrow down what gets listed from the remote API.', 'wp-rest-importer' ); ?></p>
									</div>
								</div>
								<div class="pp-filter-panel-body">

									<div class="pp-field-row">
										<div class="pp-field pp-field-half">
											<label for="pp-date-after" class="pp-label"><?php esc_html_e( 'After date', 'wp-rest-importer' ); ?></label>
											<input type="date" id="pp-date-after" name="pp_date_after" class="pp-input" />
										</div>
										<div class="pp-field pp-field-half">
											<label for="pp-date-before" class="pp-label"><?php esc_html_e( 'Before date', 'wp-rest-importer' ); ?></label>
											<input type="date" id="pp-date-before" name="pp_date_before" class="pp-input" />
										</div>
									</div>

									<div class="pp-field-row">
										<div class="pp-field pp-field-half">
											<label for="pp-category" class="pp-label"><?php esc_html_e( 'Source category slug', 'wp-rest-importer' ); ?></label>
											<input type="text" id="pp-category" name="pp_category" class="pp-input" placeholder="news" />
											<p class="pp-advanced-hint"><?php esc_html_e( 'Only fetch posts in this category on the source site.', 'wp-rest-importer' ); ?></p>
										</div>
										<div class="pp-field pp-field-half">
											<label for="pp-status-filter" class="pp-label"><?php esc_html_e( 'Remote post status', 'wp-rest-importer' ); ?></label>
											<select id="pp-status-filter" name="pp_status_filter" class="pp-select">
												<option value=""><?php esc_html_e( 'Any', 'wp-rest-importer' ); ?></option>
												<option value="publish"><?php esc_html_e( 'Published', 'wp-rest-importer' ); ?></option>
												<option value="draft"><?php esc_html_e( 'Draft', 'wp-rest-importer' ); ?></option>
												<option value="private"><?php esc_html_e( 'Private', 'wp-rest-importer' ); ?></option>
											</select>
										</div>
									</div>

									<div class="pp-field pp-field-no-margin">
										<label for="pp-cpt-rest-base" class="pp-label"><?php esc_html_e( 'Custom post type (REST base)', 'wp-rest-importer' ); ?></label>
										<input type="text" id="pp-cpt-rest-base" name="pp_cpt_rest_base" class="pp-input pp-input-mono" placeholder="portfolio" />
									</div>
								</div>
							</fieldset>

							<div class="pp-step-card pp-card">
								<div class="pp-step-head">
									<span class="pp-step-num">3</span>
									<div>
										<h3 class="pp-step-title"><?php esc_html_e( 'Run import', 'wp-rest-importer' ); ?></h3>
										<p class="pp-step-desc"><?php esc_html_e( 'Duplicate handling, author, and credentials.', 'wp-rest-importer' ); ?></p>
									</div>
								</div>
								<div class="pp-step-body">
									<div class="pp-field-row pp-options-row">
										<div class="pp-field pp-field-half">
											<label for="pp-import-mode" class="pp-label"><?php esc_html_e( 'If slug already exists here', 'wp-rest-importer' ); ?></label>
											<select id="pp-import-mode" name="pp_import_mode" class="pp-select">
												<option value="overwrite" <?php selected( $settings['default_import_mode'], 'overwrite' ); ?>><?php esc_html_e( 'Overwrite', 'wp-rest-importer' ); ?></option>
												<option value="new_only" <?php selected( $settings['default_import_mode'], 'new_only' ); ?>><?php esc_html_e( 'Skip (new only)', 'wp-rest-importer' ); ?></option>
												<option value="update_only" <?php selected( $settings['default_import_mode'], 'update_only' ); ?>><?php esc_html_e( 'Update only', 'wp-rest-importer' ); ?></option>
											</select>
										</div>
										<div class="pp-field pp-field-half">
											<label for="pp-assign-author" class="pp-label"><?php esc_html_e( 'Author on this site', 'wp-rest-importer' ); ?></label>
											<select id="pp-assign-author" name="pp_assign_author" class="pp-select">
										<?php
										$current_user_id = get_current_user_id();
										$users           = get_users(
											[
												'role__in' => [ 'administrator', 'editor', 'author' ],
												'orderby'  => 'display_name',
												'order'    => 'ASC',
											]
										);
										foreach ( $users as $user ) {
											printf(
												'<option value="%d"%s>%s</option>',
												(int) $user->ID,
												selected( $user->ID, $current_user_id, false ),
												esc_html( $user->display_name . ' (' . $user->user_login . ')' )
											);
										}
										?>
											</select>
										</div>
									</div>

									<details class="pp-advanced-details">
										<summary class="pp-advanced-summary">
											<span class="dashicons dashicons-lock" aria-hidden="true"></span>
											<?php esc_html_e( 'Source credentials (optional)', 'wp-rest-importer' ); ?>
											<span class="pp-advanced-badge"><?php esc_html_e( 'Better Gutenberg blocks', 'wp-rest-importer' ); ?></span>
										</summary>
										<div class="pp-advanced-body">
											<p class="pp-hint"><?php esc_html_e( 'Application Password from the source site (Users → Profile). Needed when raw block content is restricted.', 'wp-rest-importer' ); ?></p>

										<div class="pp-field">
											<label for="pp-source-username" class="pp-label">
												<?php esc_html_e( 'Username', 'wp-rest-importer' ); ?>
											</label>
											<input
												type="text"
												id="pp-source-username"
												name="pp_source_username"
												class="pp-input"
												autocomplete="off"
											/>
										</div>

										<div class="pp-field pp-field-no-margin">
											<label for="pp-source-app-password" class="pp-label">
												<?php esc_html_e( 'Application Password', 'wp-rest-importer' ); ?>
											</label>
											<input
												type="password"
												id="pp-source-app-password"
												name="pp_source_app_password"
												class="pp-input"
												autocomplete="new-password"
											/>
										</div>
										</div>
									</details>

									<label class="pp-toggle">
										<input type="checkbox" id="pp-run-background" name="pp_run_background" value="1" checked />
										<span class="pp-toggle-track" aria-hidden="true"></span>
										<span class="pp-toggle-label">
											<strong><?php esc_html_e( 'Background import', 'wp-rest-importer' ); ?></strong>
											<small><?php esc_html_e( 'Runs via WP-Cron — safe to close this tab', 'wp-rest-importer' ); ?></small>
										</span>
									</label>

									<button type="submit" id="pp-submit" class="pp-btn pp-btn-primary pp-btn-block pp-btn-xl">
										<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
										<?php esc_html_e( 'Start import', 'wp-rest-importer' ); ?>
									</button>
								</div>
							</div>
						</form>
					</div><!-- .pp-col-wizard -->

					<div class="pp-col-monitor">
						<div class="pp-monitor pp-card pp-status-card">
							<div class="pp-monitor-header">
								<h3 class="pp-monitor-title"><?php esc_html_e( 'Preview', 'wp-rest-importer' ); ?></h3>
								<p class="pp-monitor-sub"><?php esc_html_e( 'What will happen when you start', 'wp-rest-importer' ); ?></p>
							</div>

							<div class="pp-flow" aria-hidden="false">
								<div class="pp-flow-node pp-flow-source">
									<span class="pp-flow-label"><?php esc_html_e( 'From', 'wp-rest-importer' ); ?></span>
									<span id="pp-flow-source-url" class="pp-flow-value"><?php esc_html_e( 'Source URL…', 'wp-rest-importer' ); ?></span>
								</div>
								<div class="pp-flow-arrow" aria-hidden="true">
									<span class="dashicons dashicons-arrow-right-alt"></span>
								</div>
								<div class="pp-flow-node pp-flow-dest">
									<span class="pp-flow-label"><?php esc_html_e( 'To', 'wp-rest-importer' ); ?></span>
									<span class="pp-flow-value"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
								</div>
							</div>

							<div id="pp-import-summary" class="pp-chip-row" role="status" aria-live="polite"></div>

							<div id="pp-idle-state" class="pp-idle-state">
								<div class="pp-idle-visual" aria-hidden="true">
									<span class="pp-idle-ring"></span>
									<span class="dashicons dashicons-chart-bar"></span>
								</div>
								<p class="pp-idle-title"><?php esc_html_e( 'Waiting to start', 'wp-rest-importer' ); ?></p>
								<p class="pp-idle-desc"><?php esc_html_e( 'Complete the steps on the left. Live progress and logs appear here during import.', 'wp-rest-importer' ); ?></p>
							</div>

							<div id="pp-status-area" class="pp-status-panel" style="display:none;" aria-live="polite">
								<div class="pp-progress-section">
									<div class="pp-progress-header">
										<div class="pp-progress-top">
											<span id="pp-progress-spinner" class="dashicons dashicons-update pp-spin" aria-hidden="true"></span>
											<span id="pp-progress-text" class="pp-progress-label">0 <?php esc_html_e( 'of', 'wp-rest-importer' ); ?> 0 <?php esc_html_e( 'imported', 'wp-rest-importer' ); ?></span>
											<span id="pp-progress-pct" class="pp-progress-pct">0%</span>
										</div>
										<div class="pp-progress-wrap">
											<div class="pp-progress-bar-outer" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pp-progress-bar-wrap">
												<div class="pp-progress-bar-inner" id="pp-progress-bar"></div>
											</div>
										</div>
									</div>
									<div class="pp-stat-row" id="pp-stat-row">
										<div class="pp-stat">
											<span class="pp-stat-value" id="pp-stat-done">0</span>
											<span class="pp-stat-label"><?php esc_html_e( 'Imported', 'wp-rest-importer' ); ?></span>
										</div>
										<div class="pp-stat">
											<span class="pp-stat-value" id="pp-stat-queued">0</span>
											<span class="pp-stat-label"><?php esc_html_e( 'In queue', 'wp-rest-importer' ); ?></span>
										</div>
										<div class="pp-stat">
											<span class="pp-stat-value" id="pp-stat-skipped">0</span>
											<span class="pp-stat-label"><?php esc_html_e( 'Skipped', 'wp-rest-importer' ); ?></span>
										</div>
									</div>
									<div id="pp-notice" class="pp-status-notice" style="display:none;"><p id="pp-notice-text"></p></div>
									<p id="pp-elapsed" class="pp-elapsed"></p>
								</div>

								<div class="pp-import-actions" id="pp-import-actions" style="display:none;">
									<button type="button" id="pp-resume-import" class="pp-btn pp-btn-primary pp-btn-small" style="display:none;"><?php esc_html_e( 'Resume', 'wp-rest-importer' ); ?></button>
									<button type="button" id="pp-cancel-import" class="pp-btn pp-btn-secondary pp-btn-small"><?php esc_html_e( 'Cancel', 'wp-rest-importer' ); ?></button>
									<button type="button" id="pp-clear-session" class="pp-btn pp-btn-secondary pp-btn-small"><?php esc_html_e( 'Clear session', 'wp-rest-importer' ); ?></button>
									<button type="button" id="pp-download-log" class="pp-btn pp-btn-secondary pp-btn-small"><?php esc_html_e( 'Download CSV', 'wp-rest-importer' ); ?></button>
								</div>

								<div class="pp-log-section">
									<div class="pp-section-heading">
										<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
										<?php esc_html_e( 'Live log', 'wp-rest-importer' ); ?>
									</div>
									<div class="pp-table-scroll pp-log-scroll">
										<table class="pp-table">
											<thead>
												<tr>
													<th><?php esc_html_e( 'Title', 'wp-rest-importer' ); ?></th>
													<th><?php esc_html_e( 'Type', 'wp-rest-importer' ); ?></th>
													<th><?php esc_html_e( 'Format', 'wp-rest-importer' ); ?></th>
													<th><?php esc_html_e( 'Status', 'wp-rest-importer' ); ?></th>
													<th><?php esc_html_e( 'Time', 'wp-rest-importer' ); ?></th>
												</tr>
											</thead>
											<tbody id="pp-log-body">
												<tr id="pp-log-empty" class="pp-log-empty-row">
													<td colspan="5"><?php esc_html_e( 'Imported items will appear here as they are processed.', 'wp-rest-importer' ); ?></td>
												</tr>
											</tbody>
										</table>
									</div>
									<div id="pp-log-footer" class="pp-log-footer" style="display:none;">
										<button type="button" id="pp-log-load-more" class="pp-btn pp-btn-secondary pp-btn-small">
											<?php esc_html_e( 'Load more', 'wp-rest-importer' ); ?>
										</button>
										<span id="pp-log-count" class="pp-log-count"></span>
									</div>
								</div>
							</div><!-- #pp-status-area -->

							<div id="pp-warning-wrap" class="pp-alert pp-alert-warn">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<p><?php esc_html_e( 'Matching slugs on this site are overwritten. Best on a fresh site or when you intend to replace content.', 'wp-rest-importer' ); ?></p>
							</div>
						</div><!-- .pp-monitor -->
					</div><!-- .pp-col-monitor -->

				</div><!-- .pp-import-layout -->
			</div><!-- #pp-tab-import -->

			<!-- ══════════════════════════════════════════════════════
			     Reassign Authors tab
			     ══════════════════════════════════════════════════════ -->
			<div id="pp-tab-reassign" class="pp-tab-panel" style="display:none;">
				<div class="pp-reassign-layout">

					<!-- Info + scan card -->
					<div class="pp-card pp-reassign-info-card">
						<div class="pp-info-card-body">
							<span class="dashicons dashicons-admin-users pp-info-icon"></span>
							<div class="pp-info-text">
								<strong><?php esc_html_e( 'Author Reassignment', 'wp-rest-importer' ); ?></strong>
								<p><?php esc_html_e( 'Scan posts and pages imported with WP REST Importer and match original authors to local WordPress users by login name.', 'wp-rest-importer' ); ?></p>
							</div>
						</div>
						<button id="pp-scan-btn" class="pp-btn pp-btn-secondary">
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( 'Scan Imported Posts', 'wp-rest-importer' ); ?>
						</button>
					</div>

					<!-- Results (shown after scan) -->
					<div id="pp-reassign-results" style="display:none;">
						<div class="pp-card">
							<div class="pp-table-scroll">
								<table class="pp-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Login', 'wp-rest-importer' ); ?></th>
											<th><?php esc_html_e( 'Display Name', 'wp-rest-importer' ); ?></th>
											<th><?php esc_html_e( 'Posts', 'wp-rest-importer' ); ?></th>
											<th><?php esc_html_e( 'WP User Found', 'wp-rest-importer' ); ?></th>
											<th><?php esc_html_e( 'Match Status', 'wp-rest-importer' ); ?></th>
										</tr>
									</thead>
									<tbody id="pp-reassign-body"></tbody>
								</table>
							</div>

							<div class="pp-reassign-action-bar">
								<span class="pp-match-summary" id="pp-match-summary"></span>
								<button id="pp-run-reassign-btn" class="pp-btn pp-btn-primary">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Run Reassignment', 'wp-rest-importer' ); ?>
								</button>
							</div>

							<div id="pp-reassign-notice" style="display:none;">
								<p id="pp-reassign-notice-text"></p>
							</div>
						</div>
					</div><!-- #pp-reassign-results -->

				</div><!-- .pp-reassign-layout -->
			</div><!-- #pp-tab-reassign -->

			<div id="pp-tab-settings" class="pp-tab-panel" style="display:none;">
				<div class="pp-card pp-settings-card">
					<h2 class="pp-card-heading">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Plugin Settings', 'wp-rest-importer' ); ?>
					</h2>
					<form id="wpresti-settings-form">
						<div class="pp-field-row">
							<div class="pp-field pp-field-half">
								<label for="pp-set-batch-size" class="pp-label"><?php esc_html_e( 'Batch size', 'wp-rest-importer' ); ?></label>
								<input type="number" min="1" max="20" id="pp-set-batch-size" name="batch_size" class="pp-input" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
							</div>
							<div class="pp-field pp-field-half">
								<label for="pp-set-page-size" class="pp-label"><?php esc_html_e( 'REST page size', 'wp-rest-importer' ); ?></label>
								<input type="number" min="1" max="100" id="pp-set-page-size" name="rest_page_size" class="pp-input" value="<?php echo esc_attr( (string) $settings['rest_page_size'] ); ?>" />
							</div>
						</div>
						<div class="pp-field">
							<label for="pp-set-default-mode" class="pp-label"><?php esc_html_e( 'Default import mode', 'wp-rest-importer' ); ?></label>
							<select id="pp-set-default-mode" name="default_import_mode" class="pp-select">
								<option value="overwrite" <?php selected( $settings['default_import_mode'], 'overwrite' ); ?>><?php esc_html_e( 'Create & update', 'wp-rest-importer' ); ?></option>
								<option value="new_only" <?php selected( $settings['default_import_mode'], 'new_only' ); ?>><?php esc_html_e( 'New only', 'wp-rest-importer' ); ?></option>
								<option value="update_only" <?php selected( $settings['default_import_mode'], 'update_only' ); ?>><?php esc_html_e( 'Update only', 'wp-rest-importer' ); ?></option>
							</select>
						</div>
						<div class="pp-field">
							<label for="pp-set-rate-limit" class="pp-label"><?php esc_html_e( 'AJAX steps per minute (per user)', 'wp-rest-importer' ); ?></label>
							<input type="number" min="10" max="120" id="pp-set-rate-limit" name="rate_limit_per_min" class="pp-input" value="<?php echo esc_attr( (string) $settings['rate_limit_per_min'] ); ?>" />
						</div>
						<label class="pp-checkbox-label">
							<input type="checkbox" name="ssl_verify" value="1" <?php checked( $settings['ssl_verify'] ); ?> />
							<?php esc_html_e( 'Verify SSL certificates on remote requests', 'wp-rest-importer' ); ?>
						</label>
						<label class="pp-checkbox-label">
							<input type="checkbox" name="email_on_complete" value="1" <?php checked( $settings['email_on_complete'] ); ?> />
							<?php esc_html_e( 'Email admin when background import completes', 'wp-rest-importer' ); ?>
						</label>
						<button type="submit" id="pp-save-settings" class="pp-btn pp-btn-primary">
							<?php esc_html_e( 'Save Settings', 'wp-rest-importer' ); ?>
						</button>
						<div id="pp-settings-notice" class="pp-settings-notice" style="display:none;"></div>
					</form>
				</div>
			</div><!-- #pp-tab-settings -->

			<div id="wpresti-footer">
				<div id="wpresti-footer-inner">
					<span class="wpresti-footer-brand">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'WP REST Importer by', 'wp-rest-importer' ); ?> <strong>Faisal Yaqoob</strong>
					</span>
					<span class="wpresti-footer-cta">
						<?php esc_html_e( 'Need help or custom WordPress development?', 'wp-rest-importer' ); ?>
						<a href="https://fysalyaqoob.com" target="_blank" rel="noopener">
							<span class="dashicons dashicons-admin-site-alt3"></span>
							fysalyaqoob.com
						</a>
					</span>
				</div>
			</div>

		</div><!-- #wpresti-wrap -->
		<?php
	}

	/**
	 * Post types that can receive imported content on this site.
	 *
	 * @return WP_Post_Type[]
	 */
	private static function get_importable_post_types(): array {
		$types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);

		unset( $types['attachment'] );

		/**
		 * Filter which local post types appear in the “Import as” dropdown.
		 *
		 * @param WP_Post_Type[] $types
		 */
		$types = apply_filters( 'wpresti_importable_post_types', $types );

		return array_values( $types );
	}
}
