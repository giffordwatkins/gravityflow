<?php
/**
 * Gravity Flow Step Notification
 *
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Notification
 * @copyright   Copyright (c) 2015-2017, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Gravity_Flow_Step_Notification extends Gravity_Flow_Step_Feed_Add_On {
	public $_step_type = 'notification';

	public function get_label() {
		return esc_html__( 'Notification', 'gravityflow' );
	}

	public function get_icon_url() {
		return '<i class="fa fa-envelope-o"></i>';
	}

	public function get_settings() {
		$form    = $this->get_form();
		$choices = array();

		foreach ( $form['notifications'] as $notification ) {
			$choices[] = array(
				'label' => $notification['name'],
				'name'  => 'notification_id_' . $notification['id'],
			);
		}

		$form_notification_fields = array(
			array(
				'name'     => 'notification',
				'label'    => esc_html__( 'Gravity Forms Notifications', 'gravityflow' ),
				'type'     => 'checkbox',
				'required' => false,
				'choices'  => $choices,
			),
		);

		$settings_api                 = $this->get_common_settings_api();
		$workflow_notification_fields = $settings_api->get_setting_notification( array(
			'name_prefix'      => 'workflow',
			'label'            => __( 'Workflow notification', 'gravityflow' ),
			'tooltip'          => __( 'Enable this setting to send an email.', 'gravityflow' ),
			'checkbox_label'   => __( 'Enabled', 'gravityflow' ),
			'checkbox_tooltip' => '',
			'send_to_fields'   => true,
			'resend_field'     => false,
		) );

		return array(
			'title'  => 'Notification',
			'fields' => array_merge( $this->get_email_add_on_fields(), $form_notification_fields, $workflow_notification_fields ),
		);
	}

	/**
	 * If a compatible email add-on is available get a checkbox setting containing the available feeds.
	 *
	 * @since 1.9.2-dev
	 *
	 * @return array
	 */
	public function get_email_add_on_fields() {
		$fields = array();

		if ( $email_add_on = $this->get_add_on_instance() ) {
			$settings           = parent::get_settings();
			$fields             = $settings['fields'];
			$fields[0]['label'] = sprintf( esc_html__( '%s Feeds', 'gravityflow' ), $email_add_on->get_short_title() );
			if ( isset( $fields[0]['required'] ) ) {
				$fields[0]['required'] = false;
			}
		}

		return $fields;
	}

	public function process() {
		$this->log_debug( __METHOD__ . '(): starting' );

		$this->send_form_notifications();
		$this->send_workflow_notification();

		if ( $this->get_add_on_instance() ) {
			parent::process();
		}

		return true;
	}

	/**
	 * Send the selected form notifications.
	 *
	 * @since 1.9.2-dev
	 */
	public function send_form_notifications() {
		/* Ensure compatibility with Gravity PDF 3.x */
		if ( defined( 'PDF_EXTENDED_VERSION' ) && version_compare( PDF_EXTENDED_VERSION, '4.0-beta1', '<' ) && class_exists( 'GFPDF_Core' ) ) {
			global $gfpdf;
			if ( empty( $gfpdf ) ) {
				$gfpdf = new GFPDF_Core();
			}
		}

		$entry = $this->get_entry();
		$form  = $this->get_form();

		foreach ( $form['notifications'] as $notification ) {
			$notification_id = $notification['id'];
			$setting_key     = 'notification_id_' . $notification_id;
			if ( $this->{$setting_key} ) {
				if ( ! GFCommon::evaluate_conditional_logic( rgar( $notification, 'conditionalLogic' ), $form, $entry ) ) {
					$this->log_debug( __METHOD__ . "(): Notification conditional logic not met, not processing notification (#{$notification_id} - {$notification['name']})." );
					continue;
				}

				Gravity_Flow_Email::send_notification( $notification, $form, $entry );
				$this->log_debug( __METHOD__ . "(): Notification sent (#{$notification_id} - {$notification['name']})." );

				$this->add_note( sprintf( esc_html__( 'Sent Notification: %s', 'gravityflow' ), $notification['name'] ) );
			}
		}
	}

	/**
	 * Send the workflow notification, if enabled.
	 *
	 * @since 1.9.2-dev
	 */
	public function send_workflow_notification() {

		if ( ! $this->workflow_notification_enabled ) {
			return;
		}

		$type      = 'workflow';
		$assignees = $this->get_notification_assignees( $type );

		if ( empty( $assignees ) ) {
			return;
		}

		$notification = $this->get_notification( $type );
		$this->send_notifications( $assignees, $notification );

		$note = esc_html__( 'Sent Notification: ', 'gravityflow' ) . $this->get_name();
		$this->add_note( $note );

	}

	/**
	 * Prevent the notifications assigned to the current step from being sent during form submission.
	 */
	public function intercept_submission() {
		$form_id = $this->get_form_id();
		add_filter( "gform_disable_notification_{$form_id}", array( $this, 'maybe_disable_notification' ), 10, 2 );
		if ( $this->get_add_on_instance() ) {
			parent::intercept_submission();
		}
	}

	/**
	 * Prevents the current notification from being sent during form submission if it is selected for this step.
	 *
	 * @param bool  $is_disabled  Indicates if the current notification has already been disabled.
	 * @param array $notification The current notifications properties.
	 *
	 * @return bool
	 */
	public function maybe_disable_notification( $is_disabled, $notification ) {
		$setting_key = 'notification_id_' . $notification['id'];

		return $this->{$setting_key} ? true : $is_disabled;
	}

	/**
	 * If a compatible email add-on is available get the instance.
	 *
	 * @since 1.9.2-dev
	 *
	 * @return false|GFFeedAddOn
	 */
	public function get_add_on_instance() {
		return Gravity_Flow_Email::get_add_on_instance();
	}

	/**
	 * Override Gravity_Flow_Step_Feed_Add_On::is_supported so the class_exists check is not performed.
	 *
	 * @since 1.9.2-dev
	 *
	 * @return bool
	 */
	public function is_supported() {
		return true;
	}

	/**
	 * Override Gravity_Flow_Step_Feed_Add_On::status_evaluation so the step will complete even when an email add-on is not available.
	 *
	 * @since 1.9.2-dev
	 *
	 * @return string
	 */
	public function status_evaluation() {
		return 'complete';
	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Notification() );
