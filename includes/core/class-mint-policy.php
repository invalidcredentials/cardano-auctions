<?php
/**
 * CA_Mint_Policy — Policy (collection) generation via Anvil utility APIs.
 * A policy = a native script that locks minting to the master wallet key + time expiration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Mint_Policy {

    /**
     * Generate a new minting policy (collection).
     *
     * @param string $name            Collection name.
     * @param string $expiration_date Expiration datetime (Y-m-d H:i:s UTC). Default: 1 year from now.
     * @return array|WP_Error         { id, policy_id, name, expiration_date }
     */
    public static function generate( string $name, string $expiration_date = '' ) {
        $master = CA_Master_Wallet::get_active();
        if ( ! $master ) {
            return new WP_Error( 'ca_no_master', 'No active master wallet. Create one first.' );
        }

        $keyhash = $master['payment_keyhash'];
        if ( empty( $keyhash ) ) {
            return new WP_Error( 'ca_no_keyhash', 'Master wallet has no payment keyhash.' );
        }

        // Default expiration: 1 year from now.
        if ( empty( $expiration_date ) ) {
            $expiration_date = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
        }

        $network = CA_Settings::get_network();

        // Step 1: Convert expiration time to a slot number via Anvil.
        $exp_ms = strtotime( $expiration_date ) * 1000;
        $slot_result = CA_Anvil::post( '/v2/services/utils/network/time-to-slot', array(
            'time' => $exp_ms,
        ) );

        if ( is_wp_error( $slot_result ) ) {
            return new WP_Error( 'ca_slot_conversion_failed', 'Failed to convert time to slot: ' . $slot_result->get_error_message() );
        }

        $expiration_slot = $slot_result['slot'] ?? 0;
        if ( ! $expiration_slot ) {
            return new WP_Error( 'ca_slot_conversion_failed', 'Anvil returned no slot number.' );
        }

        // Step 2: Build the native script schema.
        $policy_schema = array(
            'type'    => 'all',
            'scripts' => array(
                array(
                    'type'    => 'sig',
                    'keyHash' => $keyhash,
                ),
                array(
                    'type' => 'before',
                    'slot' => (int) $expiration_slot,
                ),
            ),
        );

        // Step 3: Serialize the script via Anvil to get policy ID + CBOR.
        $serialize_result = CA_Anvil::post( '/v2/services/utils/native-scripts/serialize', $policy_schema );

        if ( is_wp_error( $serialize_result ) ) {
            return new WP_Error( 'ca_serialize_failed', 'Failed to serialize policy script: ' . $serialize_result->get_error_message() );
        }

        $policy_id    = $serialize_result['policyId'] ?? '';
        $script_cbor  = $serialize_result['script'] ?? '';

        if ( empty( $policy_id ) || empty( $script_cbor ) ) {
            return new WP_Error( 'ca_serialize_failed', 'Anvil returned incomplete policy data.' );
        }

        // Step 4: Store in DB.
        $db_id = CA_Mint_Policy_Model::insert( array(
            'name'              => sanitize_text_field( $name ),
            'policy_id'         => $policy_id,
            'policy_schema'     => wp_json_encode( $policy_schema ),
            'policy_script_cbor'=> $script_cbor,
            'master_wallet_id'  => (int) $master['id'],
            'expiration_date'   => $expiration_date,
            'expiration_slot'   => (int) $expiration_slot,
            'status'            => 'active',
            'network'           => $network,
        ) );

        if ( ! $db_id ) {
            return new WP_Error( 'ca_policy_insert_failed', 'Failed to store policy in database.' );
        }

        // Log event.
        CA_Event_Log_Model::log( 0, 0, 'policy_created', sprintf(
            'Minting policy "%s" created: %s (expires %s, slot %d)',
            $name, $policy_id, $expiration_date, $expiration_slot
        ), array(
            'policy_id'       => $policy_id,
            'expiration_slot' => $expiration_slot,
            'keyhash'         => $keyhash,
        ) );

        return array(
            'id'              => $db_id,
            'policy_id'       => $policy_id,
            'name'            => $name,
            'expiration_date' => $expiration_date,
        );
    }

    /**
     * Get all active policies for the current network.
     */
    public static function get_all_active(): array {
        return CA_Mint_Policy_Model::get_active( CA_Settings::get_network() );
    }

    /**
     * Get a single policy by DB id.
     */
    public static function get( int $id ): ?array {
        return CA_Mint_Policy_Model::get( $id );
    }
}
