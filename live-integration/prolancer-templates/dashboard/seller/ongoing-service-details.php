<?php if (!empty($_GET['order_id'])) {
  $order_id = $_GET['order_id'];
}

if( is_user_logged_in() ){
  // Get and validate seller_id
  $seller_id = absint(get_user_meta(get_current_user_id(), 'seller_id', true));
  if (!$seller_id) {
      return; // Exit if invalid seller_id
  }

  // Validate order_id (assuming it comes from a request parameter)
  $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
  if (!$order_id) {
      return; // Exit if invalid order_id
  }

  global $wpdb;
  $table = 'prolancer_service_orders';

  // Secure table existence check
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
      return; // Exit if table doesn't exist
  }

  // Secure query with prepared statement
  $query = $wpdb->prepare(
      "SELECT * FROM {$table} 
       WHERE id = %d 
       AND seller_id = %d 
       AND status = %s 
       ORDER BY timestamp DESC 
       LIMIT 1",
      $order_id,
      $seller_id,
      'ongoing'
  );

  // Safer result handling (using get_row instead of get_results[0])
  $result = $wpdb->get_row($query, ARRAY_A);
} ?>

<div class="white-padding mb-4">
  <h2 class="mb-0"><?php echo esc_html__( 'Ongoing Service Details', 'prolancer' ); ?></h2>
</div>

<?php if ($result){ ?>
  <div class="white-padding">
    <div class="table-responsive">
      <table class="prolancer-table">
        <thead>
          <tr>
            <th scope="col"><?php echo esc_html__( 'Title' , 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__( 'Delivery time' , 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__( 'Price' , 'prolancer'); ?></th>
            <th scope="col"><?php echo esc_html__( 'Buyer' , 'prolancer'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php
          $service_id = $result['service_id'];
          $delivery_time_id = $result['delivery_time_id'];
          $buyer_id = $result['buyer_id'];
          $seller_id = $result['seller_id'];
          $service = get_post($service_id); ?>
          <tr>
            <td><a href="<?php echo get_the_permalink($service_id); ?>" target="_blank"><?php echo esc_html( $service->post_title ); ?></a></td>
            <td><?php if (get_term_by( 'id', $delivery_time_id, 'delivery-time' )) {echo esc_html(get_term_by( 'id', $delivery_time_id, 'delivery-time' )->name);} ?></td>
            <td>
              <?php if (class_exists( 'WooCommerce' )) {
                echo wc_price($result['service_price']);
              } ?>
            </td>
            <td>
              
                <?php $buyer_image = wp_get_attachment_image ( prolancer_get_image_id(get_post_meta($buyer_id, 'buyer_profile_attachment', true )),array('50', '50') ,false);

                if (!empty($buyer_image)) {
                    echo wp_kses($buyer_image,array(
                      "img" => array(
                          "src" => array(),
                          "alt" => array(),
                          "style" => array()
                      )
                    ));
                } else {
                    echo get_avatar( get_post_field('post_author', $buyer_id), 50 );
                } ?>
              
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php 
  global $wpdb;
  $table = 'prolancer_service_messages';
  $messages = []; // Initialize as empty array

  // Secure table existence check
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
    $messages = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE `order_id` = %d",
            $order_id
        )
    ) ?: [];
  
    if($messages) { ?>
      <div class="chat-box white-padding mt-4 mb-4">
      <?php

      foreach ($messages as $message) {

        $message_sender = get_user_meta( get_current_user_id(), 'seller_id' , true );
        $sender_id = $message->sender_id;
        $receiver_id = $message->receiver_id;
        
        if ($receiver_id == $message_sender) { ?>
        <div class="chat-list message_receiver">
          <div class="row">
            <div class="col-3">
              
                <?php $sender_image = wp_get_attachment_image ( prolancer_get_image_id(get_post_meta($sender_id, 'buyer_profile_attachment', true )),array('50', '50') ,false);

                if (!empty($sender_image)) {
                    echo wp_kses($sender_image,array(
                        "img" => array(
                            "src" => array(),
                            "alt" => array(),
                            "style" => array()
                        )
                    ));
                } else {
                    echo get_avatar( get_post_field('post_author', $sender_id), 50 );
                } ?>
                <?php echo esc_attr(get_post_meta($sender_id, 'buyer_profile_name', true)) ; ?>
              
            </div>
            <div class="col-9">
              <p><?php echo pcu_message_text( $message->message ); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
          </div>
        </div>
        <?php } else { ?>
        <div class="chat-list message_sender">
          <div class="row">
            <div class="col-9">
              <p><?php echo pcu_message_text( $message->message ); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
            <div class="col-3">
              
                <?php echo esc_attr(get_post_meta($sender_id, 'seller_profile_name', true)) ; ?>
                <?php $sender_image = wp_get_attachment_image ( prolancer_get_image_id(get_post_meta($sender_id, 'seller_profile_attachment', true )),array('50', '50') ,false);

                if (!empty($sender_image)) {
                    echo wp_kses($sender_image,array(
                        "img" => array(
                            "src" => array(),
                            "alt" => array(),
                            "style" => array()
                        )
                    ));
                } else {
                    echo get_avatar( get_post_field('post_author', $sender_id), 50 );
                } ?>
              
            </div>
          </div>
        </div>
        <?php }
        } ?>
      </div>
    <?php } else { ?>
      <div class="white-padding mt-4 mb-4">
        <p><?php echo esc_html__( 'No Message found!','prolancer' ); ?></p>
      </div>
    <?php } ?>    
    <form id="send-service-message-form">
      <textarea name="message" cols="30" rows="10" placeholder="<?php echo esc_attr__( 'Type your message here...','prolancer' ); ?>"></textarea>
      <input id="upload-message-attachments" type="file" class="form-control mt-3 mb-4" accept="image/pdf/doc/docx/ppt/pptx*" data-order-id="<?php echo esc_attr($order_id) ?>" data-post-id="<?php echo esc_attr( $service->ID ); ?>" data-nonce="<?php echo wp_create_nonce('upload_file_nonce'); ?>">
    <?php pcu_attach_button(); ?>
      <input type="hidden" name="attachment_id" class="attachment-id" value="">
      <a href="#" class="send-service-message prolancer-btn" data-nonce="<?php echo wp_create_nonce('send_service_message_nonce'); ?>" data-order-id="<?php echo esc_attr($order_id) ?>" data-sender-id="<?php echo esc_attr( get_user_meta( get_current_user_id(), 'seller_id' , true ) ); ?>" data-receiver-id="<?php echo esc_attr( $buyer_id ); ?>"><?php echo esc_html__( 'Send message','prolancer' ); ?></a>
    </form>
  <?php }
} else { ?>
  <div class="white-padding">
    <p class="mb-0"><?php echo esc_html__( 'No result found!','prolancer' ); ?></p>
  </div>
<?php } ?>