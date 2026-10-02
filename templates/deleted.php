<?php
/**
 * Deleted entries admin template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Deleted Entries', 'erm-pro' ); ?></h1>

	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Host', 'erm-pro' ); ?></th>
				<th><?php esc_html_e( 'Example URL', 'erm-pro' ); ?></th>
				<th><?php esc_html_e( 'Was Blocked', 'erm-pro' ); ?></th>
				<th><?php esc_html_e( 'Deleted At', 'erm-pro' ); ?></th>
				<th><?php esc_html_e( 'Deleted By', 'erm-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $results['data'] ) ) : ?>
				<?php foreach ( $results['data'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->host ); ?></td>
						<td style="word-break:break-all;">
							<?php
							$full_url  = isset( $row->url_example ) ? erm_pro_redact_url( $row->url_example ) : '';
							$max_chars = 80;
							if ( ! empty( $full_url ) ) {
								$short = wp_html_excerpt( $full_url, $max_chars, '...' );
							} else {
								$short = '';
							}
							?>
							<span title="<?php echo esc_attr( $full_url ); ?>"><?php echo esc_html( $short ); ?></span>
						</td>
						<td><?php echo $row->was_blocked ? esc_html__( 'Yes', 'erm-pro' ) : esc_html__( 'No', 'erm-pro' ); ?></td>
						<td><?php echo esc_html( $row->deleted_timestamp ); ?></td>
						<td>
							<?php
							$user = false;
							if ( ! empty( $row->deleted_by_user ) ) {
								$user = get_userdata( $row->deleted_by_user );
							}
							echo $user ? esc_html( $user->display_name ) : esc_html__( '-', 'erm-pro' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No deleted entries found.', 'erm-pro' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	// Simple pagination
	$total_pages = max( 1, ceil( $results['total'] / $results['per_page'] ) );
	if ( $total_pages > 1 ) :
		?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $results['paged'],
							'total'     => $total_pages,
							'prev_text' => __( 'Previous', 'erm-pro' ),
							'next_text' => __( 'Next', 'erm-pro' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

</div>
