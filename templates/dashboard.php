<?php
/**
 * Dashboard Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap erm-pro-wrap">
	<div class="erm-header">
		<h1><?php echo esc_html__( 'External Requests Manager', 'erm-pro' ); ?></h1>
		<p class="erm-subtitle"><?php echo esc_html__( 'Monitor, block, and manage external HTTP requests', 'erm-pro' ); ?></p>
	</div>

	<!-- Statistics -->
	<div class="erm-stats-grid">
		<div class="erm-stat-card">
			<div class="erm-stat-number"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></div>
			<div class="erm-stat-label"><?php echo esc_html__( 'Total Requests', 'erm-pro' ); ?></div>
		</div>
		<div class="erm-stat-card erm-stat-blocked">
			<div class="erm-stat-number"><?php echo esc_html( number_format_i18n( $counts['blocked'] ) ); ?></div>
			<div class="erm-stat-label"><?php echo esc_html__( 'Blocked', 'erm-pro' ); ?></div>
		</div>
		<div class="erm-stat-card erm-stat-allowed">
			<div class="erm-stat-number"><?php echo esc_html( number_format_i18n( $counts['allowed'] ) ); ?></div>
			<div class="erm-stat-label"><?php echo esc_html__( 'Allowed', 'erm-pro' ); ?></div>
		</div>
	</div>

	<!-- Filters & Search -->
	<div class="erm-filters-section">
		<ul class="erm-filter-tabs">
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=erm-pro-logs' ) ); ?>"
				   class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
					<?php echo esc_html__( 'All', 'erm-pro' ); ?>
					<span class="count" data-count="total"><?php echo (int) $counts['total']; ?></span>
				</a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=erm-pro-logs&filter=blocked' ) ); ?>"
				   class="<?php echo $filter === 'blocked' ? 'active' : ''; ?>">
					<?php echo esc_html__( 'Blocked', 'erm-pro' ); ?>
					<span class="count" data-count="blocked"><?php echo (int) $counts['blocked']; ?></span>
				</a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=erm-pro-logs&filter=allowed' ) ); ?>"
				   class="<?php echo $filter === 'allowed' ? 'active' : ''; ?>">
					<?php echo esc_html__( 'Allowed', 'erm-pro' ); ?>
					<span class="count" data-count="allowed"><?php echo (int) $counts['allowed']; ?></span>
				</a>
			</li>
		</ul>

		<form method="get" class="erm-search-form">
			<input type="hidden" name="page" value="erm-pro-logs">
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
			<div class="erm-search-wrapper">
				<input type="search" aria-label="<?php esc_attr_e( 'Search requests', 'erm-pro' ); ?>" name="s" value="<?php echo esc_attr( $search ); ?>"
					   placeholder="<?php esc_attr_e( 'Search host or URL...', 'erm-pro' ); ?>" class="erm-search-input">
				<select name="search_by" class="erm-search-by" title="<?php esc_attr_e( 'Search by field', 'erm-pro' ); ?>">
					<option value="">-- <?php esc_html_e( 'All Fields', 'erm-pro' ); ?> --</option>
					<option value="host" <?php selected( $search_by, 'host' ); ?>><?php esc_html_e( 'Host', 'erm-pro' ); ?></option>
					<option value="url" <?php selected( $search_by, 'url' ); ?>><?php esc_html_e( 'URL', 'erm-pro' ); ?></option>
					<option value="plugin" <?php selected( $search_by, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'erm-pro' ); ?></option>
					<option value="theme" <?php selected( $search_by, 'theme' ); ?>><?php esc_html_e( 'Theme', 'erm-pro' ); ?></option>
				</select>
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Search', 'erm-pro' ); ?></button>
			</div>
		</form>
	</div>

	<!-- Bulk Actions -->
	<form method="post" id="erm-bulk-form" class="erm-requests-form">
		<?php wp_nonce_field( 'erm_bulk_action', 'erm_nonce' ); ?>

		<div class="erm-toolbar">
			<div class="erm-bulk-actions">
				<select name="erm_action" aria-label="<?php esc_attr_e( 'Bulk action', 'erm-pro' ); ?>" id="erm-bulk-action-select" class="erm-action-select">
					<option value="">-- <?php echo esc_html__( 'Bulk Actions', 'erm-pro' ); ?> --</option>
					<option value="block"><?php echo esc_html__( 'Block Selected', 'erm-pro' ); ?></option>
					<option value="unblock"><?php echo esc_html__( 'Unblock Selected', 'erm-pro' ); ?></option>
					<option value="delete"><?php echo esc_html__( 'Delete Selected', 'erm-pro' ); ?></option>
				</select>
				<button type="button" class="button button-secondary" id="erm-apply-bulk-action">
					<?php echo esc_html__( 'Apply', 'erm-pro' ); ?>
				</button>
			</div>

			<div class="erm-toolbar-right">
				<button type="button" class="button button-link-delete" id="erm-clear-all-btn">
					<?php echo esc_html__( 'Clear Logs', 'erm-pro' ); ?>
				</button>
			</div>
		</div>

		<!-- Requests Table -->
		<div class="erm-table-container">
			<table class="erm-requests-table widefat striped">
				<thead>
					<tr>
						<th class="erm-col-checkbox">
							<input type="checkbox" aria-label="<?php esc_attr_e( 'Select all requests', 'erm-pro' ); ?>" id="erm-select-all" class="erm-checkbox-all">
						</th>
						<?php if ( in_array( 'host', $display_columns, true ) ) : ?>
							<th class="erm-col-host"><?php echo esc_html__( 'Host', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'source', $display_columns, true ) ) : ?>
							<th class="erm-col-source"><?php echo esc_html__( 'Source', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'method', $display_columns, true ) ) : ?>
							<th class="erm-col-method"><?php echo esc_html__( 'Method', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'count', $display_columns, true ) ) : ?>
							<th class="erm-col-count"><?php echo esc_html__( 'Requests', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'size', $display_columns, true ) ) : ?>
							<th class="erm-col-size"><?php echo esc_html__( 'Size', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'status', $display_columns, true ) ) : ?>
							<th class="erm-col-status"><?php echo esc_html__( 'Status', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'first_request', $display_columns, true ) ) : ?>
							<th class="erm-col-first"><?php echo esc_html__( 'First Seen', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'last_request', $display_columns, true ) ) : ?>
							<th class="erm-col-last"><?php echo esc_html__( 'Last Seen', 'erm-pro' ); ?></th>
						<?php endif; ?>
						<?php if ( in_array( 'actions', $display_columns, true ) ) : ?>
							<th class="erm-col-actions"><?php echo esc_html__( 'Actions', 'erm-pro' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $results['data'] ) ) : ?>
						<tr>
							<td colspan="99" class="erm-empty-state">
								<div class="erm-no-data">
									<p><?php echo esc_html__( 'No requests found', 'erm-pro' ); ?></p>
								</div>
							</td>
						</tr>
						<?php
					else :
						foreach ( $results['data'] as $request ) :
							?>
						<tr class="erm-request-row" data-id="<?php echo esc_attr( $request->id ); ?>">
							<td class="erm-col-checkbox">
								<input type="checkbox" aria-label="
								<?php
								echo esc_attr(
									sprintf( /* translators: %s: External host name. */
										__( 'Select %s', 'erm-pro' ),
										$request->host
									)
								);
								?>
																	" class="erm-request-checkbox" value="<?php echo esc_attr( $request->id ); ?>" name="erm_ids[]">
							</td>

													<?php if ( in_array( 'host', $display_columns, true ) ) : ?>
								<td class="erm-col-host">
									<strong><?php echo esc_html( $request->host ); ?></strong>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'source', $display_columns, true ) ) : ?>
								<td class="erm-col-source">
														<?php
														if ( $request->source_plugin ) {
															echo '<span class="erm-source-badge erm-plugin">' . esc_html( $request->source_plugin ) . '</span>';
														} elseif ( $request->source_theme ) {
															echo '<span class="erm-source-badge erm-theme">' . esc_html( $request->source_theme ) . '</span>';
														} else {
															echo '<span class="erm-source-badge erm-core">' . esc_html__( 'Core', 'erm-pro' ) . '</span>';
														}
														?>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'method', $display_columns, true ) ) : ?>
								<td class="erm-col-method">
									<span class="erm-method-badge"><?php echo esc_html( $request->request_method ); ?></span>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'count', $display_columns, true ) ) : ?>
								<td class="erm-col-count">
														<?php echo esc_html( number_format_i18n( $request->request_count ) ); ?>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'size', $display_columns, true ) ) : ?>
								<td class="erm-col-size">
														<?php echo esc_html( $request->request_size > 0 ? erm_pro_format_bytes( $request->request_size ) : '-' ); ?>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'status', $display_columns, true ) ) : ?>
								<td class="erm-col-status">
														<?php
														$status_class = 'erm-allowed';
														$status_icon  = 'dashicons-yes-alt';
														$status_text  = __( 'Allowed', 'erm-pro' );

														if ( $request->is_blocked ) {
															$status_class = 'erm-blocked';
															$status_icon  = 'dashicons-shield';
															$status_text  = __( 'Blocked', 'erm-pro' );
														} elseif ( $request->rate_limit_interval > 0 ) {
															$status_class = 'erm-rate-limited';
															$status_icon  = 'dashicons-clock';
															$status_text  = __( 'Rate Limited', 'erm-pro' );
														}
														?>
									<span class="erm-status-badge <?php echo esc_attr( $status_class ); ?>">
										<span class="dashicons <?php echo esc_attr( $status_icon ); ?>"></span>
														<?php echo esc_html( $status_text ); ?>
									</span>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'first_request', $display_columns, true ) ) : ?>
								<td class="erm-col-first">
									<small><?php echo esc_html( erm_pro_time_ago( $request->first_timestamp ) ); ?></small>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'last_request', $display_columns, true ) ) : ?>
								<td class="erm-col-last">
									<small><?php echo esc_html( erm_pro_time_ago( $request->last_timestamp ) ); ?></small>
								</td>
							<?php endif; ?>

													<?php if ( in_array( 'actions', $display_columns, true ) ) : ?>
								<td class="erm-col-actions">
									<button type="button" aria-label="<?php esc_attr_e( 'Review details', 'erm-pro' ); ?>" class="button button-small erm-review-btn" data-id="<?php echo esc_attr( $request->id ); ?>" title="<?php esc_attr_e( 'Review details', 'erm-pro' ); ?>">
										<span class="dashicons dashicons-visibility"></span>
									</button>
														<?php if ( $request->rate_limit_interval && $request->rate_limit_interval > 0 ) : ?>
										<button type="button" class="button button-small erm-remove-rate-limit-btn" data-id="<?php echo esc_attr( $request->id ); ?>" data-has-rate-limit="1">
															<?php esc_html_e( 'Remove Rate Limit', 'erm-pro' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small erm-toggle-block-btn" data-id="<?php echo esc_attr( $request->id ); ?>" data-blocked="<?php echo esc_attr( $request->is_blocked ); ?>">
											<?php echo $request->is_blocked ? esc_html__( 'Unblock', 'erm-pro' ) : esc_html__( 'Block', 'erm-pro' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete erm-delete-btn" data-id="<?php echo esc_attr( $request->id ); ?>" data-has-rate-limit="<?php echo esc_attr( $request->rate_limit_interval && $request->rate_limit_interval > 0 ? '1' : '0' ); ?>" data-is-blocked="<?php echo esc_attr( $request->is_blocked ); ?>">
														<?php echo esc_html__( 'Delete', 'erm-pro' ); ?>
									</button>
								</td>
							<?php endif; ?>
						</tr>
											<?php
					endforeach;
endif;
					?>
				</tbody>
			</table>
		</div>

		<!-- Pagination -->
		<?php
		$total_pages = ceil( $results['total'] / $per_page );
		if ( $total_pages > 1 ) :
			?>
			<div class="erm-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => __( 'Previous', 'erm-pro' ),
							'next_text' => __( 'Next', 'erm-pro' ),
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	</form>

	<!-- Settings Link -->
	<div class="erm-footer">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=erm-pro-settings' ) ); ?>" class="button button-secondary">
			<span class="dashicons dashicons-admin-generic"></span>
			<?php echo esc_html__( 'Settings', 'erm-pro' ); ?>
		</a>
	</div>
</div>

<!-- Detail Modal -->
<div id="erm-detail-modal" class="erm-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="erm-detail-modal-title">
	<div class="erm-modal-content" tabindex="-1">
		<div class="erm-modal-header">
			<h2 id="erm-detail-modal-title"><?php echo esc_html__( 'Request Details', 'erm-pro' ); ?></h2>
			<button type="button" aria-label="<?php esc_attr_e( 'Close dialog', 'erm-pro' ); ?>" class="erm-modal-close">&times;</button>
		</div>
		<div class="erm-modal-body" id="erm-detail-body">
			<!-- Loaded via AJAX -->
		</div>
		<div class="erm-modal-footer">
			<div class="erm-detail-actions" id="erm-detail-actions" style="display:none; width:100%; text-align:left; border-top:1px solid #ddd; padding-top:15px; margin-bottom:15px;">
				<div class="erm-rate-limit-section" style="margin-bottom:20px;">
					<h3 style="margin-top:0;"><?php echo esc_html__( 'Rate Limiting', 'erm-pro' ); ?></h3>
					<p><?php echo esc_html__( 'Rate-limit this host across all HTTP methods:', 'erm-pro' ); ?></p>
					<div style="display:flex; gap:10px;">
						<label for="erm-rate-interval"><?php esc_html_e( 'Interval (seconds)', 'erm-pro' ); ?></label><input type="number" id="erm-rate-interval" min="0" max="31536000" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">
						<label for="erm-rate-calls"><?php esc_html_e( 'Calls per interval', 'erm-pro' ); ?></label><input type="number" id="erm-rate-calls" min="1" max="100000" value="1"><button type="button" class="button button-primary" id="erm-save-rate-limit"><?php echo esc_html__( 'Save', 'erm-pro' ); ?></button>
					</div>
					<p style="font-size:12px; color:#666; margin-top:8px;"><?php echo esc_html__( '0 = no limit, leave empty to disable rate limiting', 'erm-pro' ); ?></p>
				</div>

				<div class="erm-modal-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
					<button type="button" class="button" id="erm-modal-toggle-block"><?php echo esc_html__( 'Block', 'erm-pro' ); ?></button>
					<button type="button" class="button button-link-delete" id="erm-modal-delete"><?php echo esc_html__( 'Delete', 'erm-pro' ); ?></button>
				</div>
			</div>
			<button type="button" class="button erm-modal-close-btn"><?php echo esc_html__( 'Close', 'erm-pro' ); ?></button>
		</div>
	</div>
</div>

<!-- Clear Logs Modal -->
<div id="erm-clear-modal" class="erm-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="erm-clear-modal-title">
	<div class="erm-modal-content" tabindex="-1">
		<div class="erm-modal-header">
			<h2 id="erm-clear-modal-title"><?php echo esc_html__( 'Clear Logs', 'erm-pro' ); ?></h2>
			<button type="button" aria-label="<?php esc_attr_e( 'Close dialog', 'erm-pro' ); ?>" class="erm-modal-close">&times;</button>
		</div>
		<div class="erm-modal-body">
			<p><?php echo esc_html__( 'Choose how to clear logs:', 'erm-pro' ); ?></p>
			<div class="erm-clear-options">
				<label class="erm-clear-option">
					<input type="radio" name="erm_clear_mode" value="except_blocked" checked>
					<span class="erm-clear-title"><?php echo esc_html__( 'Clear all logs except blocked', 'erm-pro' ); ?></span>
					<span class="erm-clear-desc"><?php echo esc_html__( 'Blocked entries will remain in the list', 'erm-pro' ); ?></span>
				</label>
				<label class="erm-clear-option">
					<input type="radio" name="erm_clear_mode" value="all">
					<span class="erm-clear-title"><?php echo esc_html__( 'Clear ALL logs', 'erm-pro' ); ?></span>
					<span class="erm-clear-desc"><?php echo esc_html__( 'All entries including blocked will be cleared and unblocked', 'erm-pro' ); ?></span>
				</label>
			</div>
		</div>
		<div class="erm-modal-footer">
			<button type="button" class="button erm-modal-close-btn"><?php echo esc_html__( 'Cancel', 'erm-pro' ); ?></button>
			<button type="button" class="button button-primary" id="erm-confirm-clear-btn"><?php echo esc_html__( 'Clear', 'erm-pro' ); ?></button>
		</div>
	</div>
</div>


