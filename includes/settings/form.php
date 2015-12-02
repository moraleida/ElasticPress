<?php
/**
 * Form for setting ElasticPress preferences
 *
 * @since   1.7
 *
 * @package elasticpress
 *
 * @author  Allan Collins <allan.collins@10up.com>
 */
?>

<?php
//Set form action
$action = 'options.php';

if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
	$action = '';
}
?>

<form method="POST" action="<?php echo esc_attr( $action ); ?>">
	<?php

	settings_fields( 'elasticpress' );
	do_settings_sections( 'elasticpress' );

	if ( ( ! ep_host_by_option() && ! is_wp_error( ep_check_host() ) ) || is_wp_error( ep_check_host() ) || get_site_option( 'ep_host' ) ) {
		submit_button();
	}

	?>
</form>