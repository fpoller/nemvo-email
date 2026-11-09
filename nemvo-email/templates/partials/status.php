<?php
/**
 * Connection status card at the top of the overview.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of Admin::render_app().

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$provider      = Providers::current();
$state         = $provider->state();
$paused        = Settings::paused();
$sender_domain = Mailer::from_domain();
$dns           = '' !== $sender_domain ? Dns::stored( $sender_domain ) : null;
$settings      = admin_url( 'admin.php?page=' . Admin::SLUG_SETTINGS );
$kind          = 'error' === $state['state'] ? 'critical' : ( 'connected' === $state['state'] ? ( $paused ? 'warning' : 'good' ) : 'neutral' );
?>
<section class="nve-card nve-status is-<?php echo esc_attr( $kind ); ?>" aria-label="<?php esc_attr_e( 'Connection', 'nemvo-email' ); ?>">
	<div class="nve-status__main">
		<span class="nve-status__dot" aria-hidden="true"></span>
		<div>
			<?php if ( 'connected' === $state['state'] ) : ?>
				<p class="nve-status__title">
					<?php
					echo esc_html(
						$paused
							? __( 'Sending is paused', 'nemvo-email' )
							/* translators: %s: provider */
							: sprintf( __( 'Sending through %s', 'nemvo-email' ), $provider->name() )
					);
					?>
				</p>
				<p class="nve-status__sub">
					<?php
					/* translators: 1: sender, 2: account */
					echo esc_html( sprintf( __( 'From %1$s · account %2$s', 'nemvo-email' ), Mailer::from_name() . ' <' . Mailer::from_email( $provider ) . '>', $provider->account() ) );
					if ( $paused ) {
						echo ' · ' . esc_html__( 'messages are logged, not sent', 'nemvo-email' );
					}
					?>
				</p>
			<?php elseif ( 'error' === $state['state'] ) : ?>
				<p class="nve-status__title">
					<?php
					/* translators: %s: provider */
					echo esc_html( sprintf( __( 'Not sending: %s refuses the connection', 'nemvo-email' ), $provider->name() ) );
					?>
				</p>
				<p class="nve-status__sub"><?php echo esc_html( $state['message'] ); ?></p>
			<?php else : ?>
				<p class="nve-status__title"><?php esc_html_e( 'Email is sent by the web server', 'nemvo-email' ); ?></p>
				<p class="nve-status__sub">
					<?php
					/* translators: %s: provider */
					echo esc_html( sprintf( __( 'Messages from web servers often land in spam. Connect %s and every message goes out through it. Until then, this log shows what WordPress sends.', 'nemvo-email' ), $provider->name() ) );
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<div class="nve-status__side">
		<?php if ( 'connected' === $state['state'] && $dns && ! $dns['personal'] ) : ?>
			<a class="nve-chips" href="<?php echo esc_url( $settings . '#domain' ); ?>" title="<?php esc_attr_e( 'Domain check', 'nemvo-email' ); ?>">
				<?php
				foreach ( array( 'spf', 'dkim', 'dmarc' ) as $key ) {
					$check = $dns['checks'][ $key ];
					Icons::echo_badge( 'neutral' === $check['state'] ? 'good' : $check['state'], $check['label'] );
				}
				?>
			</a>
		<?php endif; ?>
		<?php if ( 'connected' === $state['state'] ) : ?>
			<?php
			$sent  = Stats::sent_last_day( $provider->id() );
			$limit = $provider->daily_limit();
			?>
			<div class="nve-quota" title="<?php esc_attr_e( 'Google limits how many messages an account may send in 24 hours.', 'nemvo-email' ); ?>">
				<span>
					<?php
					/* translators: 1: messages sent, 2: limit */
					echo esc_html( sprintf( __( '%1$s of %2$s in 24 h', 'nemvo-email' ), number_format_i18n( $sent ), number_format_i18n( $limit ) ) );
					?>
				</span>
				<span class="nve-quota__bar"><span style="width:<?php echo esc_attr( (string) min( 100, $limit ? round( $sent / $limit * 100, 1 ) : 0 ) ); ?>%"></span></span>
			</div>
		<?php elseif ( 'error' === $state['state'] ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $settings . '#connection' ); ?>"><?php esc_html_e( 'Fix the connection', 'nemvo-email' ); ?></a>
		<?php else : ?>
			<a class="button button-primary" href="<?php echo esc_url( Admin::setup_url() ); ?>"><?php esc_html_e( 'Start setup', 'nemvo-email' ); ?></a>
		<?php endif; ?>
	</div>
</section>
