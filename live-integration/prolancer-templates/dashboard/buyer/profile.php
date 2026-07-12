<?php 

$buyer_id = get_user_meta( get_current_user_id(), 'buyer_id' , true ); 
$buyer	  =	get_post($buyer_id); ?>

<div class="white-padding mb-4">
	<h2 class="mb-0"><?php echo esc_html__( 'Buyer Profile', 'prolancer' ); ?></h2>
</div>
<div class="row">
	<div class="col-md-8">
		<div class="white-padding">
			<form id="buyer-profile-form" enctype="multipart/form-data">
				<div class="row">
					<div class="col-md-6">
						<input type="text" name='buyer_username' class="form-control" value="<?php echo esc_attr($buyer->post_title); ?>" placeholder="<?php echo esc_attr__('Username','prolancer'); ?>">
					</div>
					<div class="col-md-6">
						<input type="text" name='buyer_profile_name' class="form-control" value="<?php echo esc_attr(get_post_meta( $buyer_id, 'buyer_profile_name' , true )); ?>" placeholder="<?php echo esc_attr__('Profile Name','prolancer'); ?>">
					</div>
					<div class="col-md-6">
						<input type="text" name='buyer_profile_title' class="form-control" value="<?php echo esc_attr(get_post_meta( $buyer_id, 'buyer_profile_title' , true )); ?>" placeholder="<?php echo esc_attr__('Profile Title','prolancer'); ?>">
					</div>
					<div class="col-md-6">
						<select name="buyer_departments" class="form-select">
							<option><?php echo esc_html__('Departments','prolancer'); ?></option>
							<?php prolancer_get_option_list('buyer-departments', 'buyer_departments', $buyer_id); ?>
						</select>
					</div>
					<div class="col-md-6">
						<select name="employees_number" class="form-select">
							<option><?php echo esc_html__('Number of Employees','prolancer'); ?></option>
							<?php prolancer_get_option_list('employees-number', 'employees_number', $buyer_id); ?>
						</select>
					</div>
					<div class="col-md-6">
						<select name="buyer_locations" class="form-select">
							<option><?php echo esc_html__('Locations','prolancer'); ?></option>
							<?php prolancer_get_option_list('buyer-locations', 'buyer_locations', $buyer_id); ?>
						</select>
					</div>
					<div class="col-md-12">
						<label><?php echo esc_html__('Description','prolancer'); ?></label>
						<textarea name="description"cols="30" rows="10" class="form-control"><?php echo esc_html($buyer->post_content); ?></textarea>
					</div>
					<div class="col-md-12">
						<?php pcu_avatar_uploader( $buyer_id, 'buyer_profile_attachment' ); ?>
					</div><div class="col-md-12">
						<div class="dropzone" data-post-id="<?php echo esc_attr($buyer_id) ?>" data-name="<?php echo esc_attr('buyer_cover_attachment'); ?>" data-nonce="<?php echo wp_create_nonce( 'upload_file_nonce' ) ?>">
							<input type="file" name="upload-file" class="upload-file" id="upload-cover-attachment" data-name="<?php echo esc_attr('buyer_cover_attachment'); ?>"   data-nonce="<?php echo wp_create_nonce( 'upload_file_nonce' ) ?>" data-post-id="<?php echo esc_attr($buyer_id) ?>">
							<label for="upload-cover-attachment"><strong><?php echo esc_html__( 'Choose a Cover Image', 'prolancer' ) ?></strong><span class="box__dragndrop"> <?php echo esc_html__( 'or drag it here', 'prolancer' ) ?></span>.</label>
							<div class="progress">
								<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
							</div>
						</div>
					</div>
					<div class="col-md-12">
						<input type="url" name="facebook" class="form-control" placeholder="<?php echo esc_attr__('Facebook','prolancer'); ?>" value="<?php echo esc_url(get_the_author_meta( 'facebook', get_current_user_id())); ?>">
					</div>
					<div class="col-md-12">
						<input type="url" name="twitter" class="form-control" placeholder="<?php echo esc_attr__('Twitter','prolancer'); ?>" value="<?php echo esc_url(get_the_author_meta( 'twitter', get_current_user_id())); ?>">
					</div>
					<div class="col-md-12">
						<input type="url" name="linkedin" class="form-control" placeholder="<?php echo esc_attr__('Linkedin','prolancer'); ?>" value="<?php echo esc_url(get_the_author_meta( 'linkedin', get_current_user_id())); ?>">
					</div>
					<div class="col-md-12">
						<input type="url" name="github" class="form-control" placeholder="<?php echo esc_attr__('Github','prolancer'); ?>" value="<?php echo esc_url(get_the_author_meta( 'github' , get_current_user_id())); ?>">
					</div>
					<div class="col-md-12 mb-4">
						<input type="url" name="dribbble" class="form-control" placeholder="<?php echo esc_attr__('Dribbble','prolancer'); ?>" value="<?php echo esc_url(get_the_author_meta( 'dribbble', get_current_user_id())); ?>">
					</div>
					<div class="col-md-12">
						<a href="#" id="update-buyer-profile" class="prolancer-btn" data-buyer-id="<?php echo esc_attr($buyer_id) ?>" data-nonce="<?php echo wp_create_nonce('create_buyer_nonce'); ?>"><?php echo esc_html__( 'Update Profile', 'prolancer' ); ?></a>
					</div>
				</div>
			</form>
		</div>
	</div>
	<div class="col-md-4">
		<div class="white-padding">
			<form id="password-form" enctype="multipart/form-data">
				<div class="col-md-12">
					<input type="password" name="old_password" class="form-control" autocomplete="off" placeholder="<?php echo esc_attr__('Old password','prolancer'); ?>" required>
				</div>
				<div class="col-md-12">
					<input type="password" name="new_password" class="form-control" autocomplete="off" placeholder="<?php echo esc_attr__('New password','prolancer'); ?>" required>
				</div>
				<div class="col-md-12">
					<input type="password" name="confirm_password" class="form-control" autocomplete="off" placeholder="<?php echo esc_attr__('Confirm password','prolancer'); ?>" required>
				</div>
				<div class="col-md-12">
					<a href="#" id="change-password" class="prolancer-btn" data-user-id="<?php echo get_current_user_id(); ?>" data-nonce="<?php echo wp_create_nonce('change_password_nonce'); ?>"><?php echo esc_html__( 'Change password', 'prolancer' ); ?></a>
				</div>
			</form>
		</div>

		<div class="white-padding mt-4">
			<div class="text-center">
				<a href="#" class="btn btn-danger text-white w-100 rounded" data-bs-toggle="modal" data-bs-target="#delete<?php echo get_current_user_id(); ?>">
          <?php echo esc_html__('Delete Account','prolancer'); ?>
        </a>
      </div>
      <!-- Modal -->
      <div class="modal fade" id="delete<?php echo get_current_user_id(); ?>" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="staticBackdropLabel"><?php echo esc_html__( 'Delete Account', 'prolancer' ) ; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p><?php echo esc_html__('Are you sure?','prolancer'); ?></p>
                <a href="#"  id="delete-account" class="btn btn-danger text-white" data-nonce="<?php echo wp_create_nonce('delete_account_nonce'); ?>" data-user-id="<?php echo get_current_user_id(); ?>"><?php echo esc_html__( 'Delete', 'prolancer' ); ?></a>
              </div>
            </div>
        </div>
      </div>
		</div>
	</div>
</div>