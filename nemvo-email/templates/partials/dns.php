<?php
/**
 * DNS check results with the records to publish.
 *
 * Expects $dns (from Dns::check()) and $dns_back (where the DKIM selector
 * form returns to) in the including scope.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of the including method.

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

if ( empty( $dns ) ) {
	return;
}

$check_order = array( 'spf', 'dkim', 'dmarc', 'mx' );
$copy        = __( 'Copy', 'nemvo-email' );
?>
<div class="nve-dns" data-state="<?php echo esc_attr( $dns['state'] ); ?>">
	<?php if ( $dns['personal'] ) : ?>
		<p class="nve-note">
			<?php
			/* translators: %s: domain */
			echo esc_html( sprintf( __( 'You send from a %s address. Google takes care of its SPF, DKIM and DMARC, so there is nothing to publish.', 'nemvo-email' ), $dns['domain'] ) );
			?>
		</p>
	<?php else : ?>
		<p class="nve-dns__meta">
			<?php
			if ( '' !== $dns['host']['name'] ) {
				/* translators: 1: domain, 2: DNS host like Cloudflare, 3: name server */
				echo esc_html( sprintf( __( 'The DNS of %1$s is hosted at %2$s (%3$s). Records are added there.', 'nemvo-email' ), $dns['zone'], $dns['host']['name'], $dns['host']['ns'] ) );
			} elseif ( '' !== $dns['host']['ns'] ) {
				/* translators: 1: domain, 2: name server */
				echo esc_html( sprintf( __( 'The DNS of %1$s is served by %2$s. Records are added wherever you manage the domain.', 'nemvo-email' ), $dns['zone'], $dns['host']['ns'] ) );
			}
			echo ' ';
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'Checked %s.', 'nemvo-email' ), wp_date( get_option( 'time_format' ) . ', ' . get_option( 'date_format' ), $dns['time'] ) ) );
			?>
		</p>
		<ul class="nve-checklist nve-dns__list">
			<?php
			foreach ( $check_order as $key ) :
				if ( empty( $dns['checks'][ $key ] ) ) {
					continue;
				}
				$check = $dns['checks'][ $key ];
				?>
				<li class="is-<?php echo esc_attr( $check['state'] ); ?>" id="nve-dns-<?php echo esc_attr( $key ); ?>">
					<?php Icons::echo_badge( $check['state'], '', $check['state'] ); ?>
					<div>
						<p class="nve-dns__title"><span class="nve-dns__label"><?php echo esc_html( $check['label'] ); ?></span> <strong><?php echo esc_html( $check['title'] ); ?></strong></p>
						<p><?php echo esc_html( $check['detail'] ); ?></p>
						<?php if ( ! empty( $check['notice'] ) ) : ?>
							<p class="nve-dns__notice"><?php echo esc_html( $check['notice'] ); ?></p>
						<?php endif; ?>

						<?php if ( ! empty( $check['steps'] ) ) : ?>
							<ol class="nve-steps">
								<?php foreach ( $check['steps'] as $step ) : ?>
									<li><?php echo wp_kses( $step, Admin::inline_html() ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>

						<?php if ( 'dkim' === $key ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nve-selector">
								<input type="hidden" name="action" value="nemvo_email_dns">
								<input type="hidden" name="domain" value="<?php echo esc_attr( $dns['domain'] ); ?>">
								<input type="hidden" name="_back" value="<?php echo esc_url( $dns_back ?? '' ); ?>">
								<?php wp_nonce_field( 'nemvo_email_dns' ); ?>
								<label>
									<span><?php esc_html_e( 'DKIM selector', 'nemvo-email' ); ?></span>
									<input type="text" name="dkim_selector" value="<?php echo esc_attr( $check['selector'] ?? '' ); ?>" placeholder="google" spellcheck="false" autocomplete="off">
								</label>
								<button type="submit" class="button"><?php esc_html_e( 'Check', 'nemvo-email' ); ?></button>
								<?php foreach ( (array) ( $check['suggest'] ?? array() ) as $suggestion ) : ?>
									<button type="submit" class="button" name="dkim_selector" value="<?php echo esc_attr( $suggestion ); ?>">
										<?php
										/* translators: %s: DKIM selector */
										echo esc_html( sprintf( __( 'Use "%s"', 'nemvo-email' ), $suggestion ) );
										?>
									</button>
								<?php endforeach; ?>
								<small><?php esc_html_e( 'The prefix chosen in the Google Admin console when the key was generated: the part before ._domainkey in the record name. "google" unless you changed it. Separate several with commas.', 'nemvo-email' ); ?></small>
							</form>
						<?php endif; ?>

						<?php if ( ! empty( $check['fix'] ) ) : ?>
							<?php $fix = $check['fix']; ?>
							<dl class="nve-record">
								<div>
									<dt><?php esc_html_e( 'Type', 'nemvo-email' ); ?></dt>
									<dd><code><?php echo esc_html( $fix['type'] ); ?></code></dd>
								</div>
								<div>
									<dt><?php esc_html_e( 'Name', 'nemvo-email' ); ?></dt>
									<dd>
										<code><?php echo esc_html( $fix['relative'] ); ?></code>
										<button type="button" class="nve-copy" data-nve-copy="<?php echo esc_attr( $fix['relative'] ); ?>"><?php echo esc_html( $copy ); ?></button>
										<?php if ( $fix['relative'] !== $fix['name'] ) : ?>
											<small>
												<?php
												/* translators: %s: full DNS name */
												echo esc_html( sprintf( __( 'Full name: %s', 'nemvo-email' ), $fix['name'] ) );
												?>
											</small>
										<?php endif; ?>
									</dd>
								</div>
								<div class="nve-record__value">
									<dt><?php echo 'replace' === $fix['mode'] ? esc_html__( 'Change the value to', 'nemvo-email' ) : esc_html__( 'Value', 'nemvo-email' ); ?></dt>
									<dd>
										<code><?php echo esc_html( $fix['value'] ); ?></code>
										<button type="button" class="nve-copy" data-nve-copy="<?php echo esc_attr( $fix['value'] ); ?>"><?php echo esc_html( $copy ); ?></button>
									</dd>
								</div>
							</dl>
						<?php endif; ?>

						<?php if ( ! empty( $check['found'] ) && 'good' !== $check['state'] && 'mx' !== $key ) : ?>
							<details class="nve-found">
								<summary><?php esc_html_e( 'What is published now', 'nemvo-email' ); ?></summary>
								<?php foreach ( $check['found'] as $record ) : ?>
									<code><?php echo esc_html( $record ); ?></code>
								<?php endforeach; ?>
							</details>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( 'good' !== $dns['state'] && 'neutral' !== $dns['state'] ) : ?>
			<p class="nve-muted nve-dns__wait"><?php esc_html_e( 'New records are usually visible within minutes; some DNS hosts take up to an hour. Then check again.', 'nemvo-email' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
