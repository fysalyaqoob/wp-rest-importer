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
			'manage_options',
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpresti_nonce' ),
			]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" id="wpresti-wrap">

			<h1 class="screen-reader-text"><?php esc_html_e( 'WP REST Importer', 'wp-rest-importer' ); ?></h1>

			<!-- ── Header ──────────────────────────────────────────── -->
			<div class="pp-header">
				<div class="pp-header-brand">
					<span class="pp-wordmark">WP REST Importer</span>
					<span class="pp-version-badge">v<?php echo esc_html( WPRESTI_VERSION ); ?></span>
				</div>
				<div class="pp-header-links">
					<a href="https://github.com/fysalyaqoob/wp-rest-importer#readme" target="_blank" rel="noopener" class="pp-header-link">
						<span class="dashicons dashicons-media-document"></span>
						<?php esc_html_e( 'Docs', 'wp-rest-importer' ); ?>
					</a>
					<a href="https://github.com/fysalyaqoob/wp-rest-importer/issues" target="_blank" rel="noopener" class="pp-header-link">
						<span class="dashicons dashicons-sos"></span>
						<?php esc_html_e( 'Support', 'wp-rest-importer' ); ?>
					</a>
				</div>
			</div>

			<!-- ── Tab nav ─────────────────────────────────────────── -->
			<nav id="pp-tabs" class="pp-tab-nav">
				<a href="#" class="nav-tab nav-tab-active" data-tab="import">
					<span class="dashicons dashicons-migrate"></span>
					<?php esc_html_e( 'Import', 'wp-rest-importer' ); ?>
				</a>
				<a href="#" class="nav-tab" data-tab="reassign">
					<span class="dashicons dashicons-admin-users"></span>
					<?php esc_html_e( 'Reassign Authors', 'wp-rest-importer' ); ?>
				</a>
			</nav>

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

					<!-- Left column: source config -->
					<div class="pp-col-config">
						<div class="pp-card">
							<h2 class="pp-card-heading">
								<span class="dashicons dashicons-admin-site-alt3"></span>
								<?php esc_html_e( 'Source Configuration', 'wp-rest-importer' ); ?>
							</h2>

							<form id="wpresti-form">
								<?php wp_nonce_field( 'wpresti_nonce', 'wpresti_nonce_field' ); ?>

								<div class="pp-field">
									<label for="pp-site-url" class="pp-label">
										<?php esc_html_e( 'Source Site URL', 'wp-rest-importer' ); ?>
									</label>
									<input
										type="url"
										id="pp-site-url"
										name="pp_site_url"
										class="pp-input pp-input-mono"
										placeholder="https://example.com"
										required
									/>
								</div>

								<div class="pp-field">
									<label for="pp-import-type" class="pp-label">
										<?php esc_html_e( 'Import Type', 'wp-rest-importer' ); ?>
									</label>
									<select id="pp-import-type" name="pp_import_type" class="pp-select">
										<option value="both"><?php esc_html_e( 'Posts &amp; Pages', 'wp-rest-importer' ); ?></option>
										<option value="posts"><?php esc_html_e( 'Posts only', 'wp-rest-importer' ); ?></option>
										<option value="pages"><?php esc_html_e( 'Pages only', 'wp-rest-importer' ); ?></option>
									</select>
								</div>

								<div class="pp-field">
									<label for="pp-assign-author" class="pp-label">
										<?php esc_html_e( 'Assign posts to', 'wp-rest-importer' ); ?>
									</label>
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

								<!-- Advanced Settings (collapsible) -->
								<details class="pp-advanced-details">
									<summary class="pp-advanced-summary">
										<span class="dashicons dashicons-admin-settings"></span>
										<?php esc_html_e( 'Advanced Settings', 'wp-rest-importer' ); ?>
										<span class="pp-advanced-arrow" aria-hidden="true"></span>
									</summary>

									<div class="pp-advanced-body">
										<p class="pp-advanced-section-label"><?php esc_html_e( 'Source Site Credentials (optional)', 'wp-rest-importer' ); ?></p>
										<p class="pp-advanced-hint"><?php esc_html_e( 'Required only if source site restricts raw content access. Generate via Users > Profile > Application Passwords on source site.', 'wp-rest-importer' ); ?></p>

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

								<button type="submit" id="pp-submit" class="pp-btn pp-btn-primary pp-btn-block">
									<span class="dashicons dashicons-migrate"></span>
									<?php esc_html_e( 'Start Import', 'wp-rest-importer' ); ?>
								</button>
							</form>
						</div>
					</div><!-- .pp-col-config -->

					<!-- Right column: status + log -->
					<div class="pp-col-status">
						<div class="pp-card">

							<!-- Progress area — hidden until import starts -->
							<div id="pp-status-area" style="display:none;">
								<div class="pp-progress-section">
									<div class="pp-progress-top">
										<span class="dashicons dashicons-update pp-spin"></span>
										<span id="pp-progress-text" class="pp-progress-label">0 <?php esc_html_e( 'of', 'wp-rest-importer' ); ?> 0 <?php esc_html_e( 'imported', 'wp-rest-importer' ); ?></span>
									</div>
									<div class="pp-progress-wrap">
										<div class="pp-progress-bar-outer">
											<div class="pp-progress-bar-inner" id="pp-progress-bar"></div>
										</div>
									</div>
									<div id="pp-notice" style="display:none;"><p id="pp-notice-text"></p></div>
								</div>

								<div class="pp-log-section">
									<div class="pp-section-heading">
										<span class="dashicons dashicons-list-view"></span>
										<?php esc_html_e( 'Import Log', 'wp-rest-importer' ); ?>
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
											<tbody id="pp-log-body"></tbody>
										</table>
									</div>
								</div>
							</div><!-- #pp-status-area -->

							<!-- Warning — always visible -->
							<div class="pp-warning-card">
								<span class="dashicons dashicons-warning"></span>
								<div class="pp-warning-body">
									<strong><?php esc_html_e( 'Heads up:', 'wp-rest-importer' ); ?></strong>
									<?php esc_html_e( 'Posts with matching slugs will be overwritten. Recommended for fresh installations.', 'wp-rest-importer' ); ?>
								</div>
							</div>

						</div><!-- .pp-card -->
					</div><!-- .pp-col-status -->

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
}
