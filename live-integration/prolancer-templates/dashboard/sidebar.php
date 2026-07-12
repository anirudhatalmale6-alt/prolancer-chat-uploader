<?php 
$buyer_id = get_user_meta( get_current_user_id(), 'buyer_id' , true );
$seller_id = get_user_meta( get_current_user_id(), 'seller_id' , true );
$visit_as = get_user_meta( get_current_user_id(), 'visit_as' , true );
?>

<div class="frontend-dashboard-sidebar">
	<div class="feds-widget feds-user-profile">
		<?php if ($visit_as == 'buyer'){ ?>
			<?php
			$buyer_image = wp_get_attachment_image ( prolancer_get_image_id(get_post_meta($buyer_id, 'buyer_profile_attachment', true )),array('250', '250') ,false);
            if (!empty($buyer_image)) {
    			echo wp_kses($buyer_image,array(
    				"img" => array(
				        "src" => array(),
				        "alt" => array(),
				        "style" => array()
				    )
    			));
    		} else {
    			echo get_avatar( wp_get_current_user()->ID, 250 );
    		} ?>
			<h5><?php echo esc_html(get_post_meta($buyer_id, 'buyer_profile_name', true )) ?></h5>
			<p><?php echo wp_get_current_user()->user_email ?></p>
			<a href="<?php echo get_permalink($buyer_id); ?>" target="_blank"><?php echo esc_html__( 'Preview Public Profile', 'prolancer' ); ?></a>
		<?php } elseif ($visit_as == 'seller'){ ?>
			<?php
			$seller_image = wp_get_attachment_image ( prolancer_get_image_id(get_post_meta($seller_id, 'seller_profile_attachment', true )),array('250', '250') ,false);

    		if (!empty($seller_image)) {
    			echo wp_kses($seller_image,array(
    				"img" => array(
				        "src" => array(),
				        "alt" => array(),
				        "style" => array()
				    )
    			));
    		} else {
    			echo get_avatar( wp_get_current_user()->ID, 250 );
    		} ?>
			<h5><?php echo esc_html(get_post_meta($seller_id, 'seller_profile_name', true )) ?></h5>
			<p><?php echo wp_get_current_user()->user_email ?></p>
			<a href="<?php echo get_permalink($seller_id); ?>" target="_blank"><?php echo esc_html__( 'Preview Public Profile', 'prolancer' ); ?></a>
		<?php } ?>
	</div>
	<div class="feds-widget feds-menu">
		<ul class="list-unstyled">
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=dashboard"><i class="fal fa-fw fa-home"></i><?php echo esc_html__( 'Dashboard', 'prolancer' ); ?></a>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=profile"><i class="fal fa-fw fa-user"></i><?php echo esc_html__( 'Profile', 'prolancer' ); ?></a>
			</li>
			<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=message"><i class="fal fa-fw fa-comments-alt"></i><?php echo esc_html__( 'Message', 'prolancer' ); ?></a></li>
			<li class="dropdown">
				<i class="fal fa-fw fa-tasks-alt"></i><?php echo esc_html__( 'Projects', 'prolancer' ); ?>
				<ul>
					<?php if($visit_as == 'buyer'){ ?>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=projects"><?php echo esc_html__( 'Projects', 'prolancer' ); ?></a></li>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=create-project"><?php echo esc_html__( 'Create a project', 'prolancer' ); ?></a></li>
					<?php } ?>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=ongoing-projects"><?php echo esc_html__( 'Ongoing Projects', 'prolancer' ); ?></a></li>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=cancelled-projects"><?php echo esc_html__( 'Cancelled Projects', 'prolancer' ); ?></a></li>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=completed-projects"><?php echo esc_html__( 'Completed Projects', 'prolancer' ); ?></a></li>					
				</ul>
			</li>
			<li class="dropdown">
				<i class="fal fa-fw fa-thumbtack"></i><?php echo esc_html__( 'Services', 'prolancer' ); ?>
				<ul>
					<?php if($visit_as == 'seller'){ ?>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=services"><?php echo esc_html__( 'Services', 'prolancer' ); ?></a></li>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=create-service"><?php echo esc_html__( 'Create a service', 'prolancer' ); ?></a></li>
					<?php } ?>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=ongoing-services"><?php echo esc_html__( 'Ongoing Services', 'prolancer' ); ?></a></li>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=cancelled-services"><?php echo esc_html__( 'Cancelled Services', 'prolancer' ); ?></a></li>
					<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=completed-services"><?php echo esc_html__( 'Completed Services', 'prolancer' ); ?></a></li>
					<?php if($visit_as == 'seller'){ ?>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=extras"><?php echo esc_html__( 'Extras', 'prolancer' ); ?></a></li>
						<li><a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=faqs"><?php echo esc_html__( 'FAQ', 'prolancer' ); ?></a></li>
					<?php } ?>
				</ul>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=wallet"><i class="fal fa-fw fa-university"></i><?php echo esc_html__( 'Wallet', 'prolancer' ); ?></a>
			</li>
			<?php if($visit_as == 'seller'){ ?>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=wishlist-projects"><i class="fal fa-fw fa-save"></i><?php echo esc_html__( 'Projects Wishlist', 'prolancer' ); ?></a>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=following-buyers"><i class="fal fa-fw fa-user-check"></i><?php echo esc_html__( 'Following Buyers', 'prolancer' ); ?></a>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=payouts"><i class="fal fa-fw fa-money-bill-wave-alt"></i><?php echo esc_html__( 'Payouts', 'prolancer' ); ?></a>
			</li>
			<?php } ?>
			<?php if($visit_as == 'buyer'){ ?>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=wishlist-services"><i class="fal fa-fw fa-save"></i><?php echo esc_html__( 'Services Wishlist', 'prolancer' ); ?></a>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=following-sellers"><i class="fal fa-fw fa-user-check"></i><?php echo esc_html__( 'Following Sellers', 'prolancer' ); ?></a>
			</li>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=disputes"><i class="fal fa-fw fa-shield-alt"></i><?php echo esc_html__( 'Disputes', 'prolancer' ); ?></a>
			</li>
			<?php } ?>
			<li>
				<a href="<?php if(function_exists('prolancer_get_page_url_by_template')){ echo esc_url(prolancer_get_page_url_by_template('prolancer-dashboard.php'));} if(get_option('permalink_structure')){echo"?";}else{echo"&";} ?>fed=verification"><i class="fas fa-fw fa-check-circle"></i><?php echo esc_html__( 'Verification', 'prolancer' ); ?></a>
			</li>
			<li><a href="<?php echo wp_logout_url( home_url() ); ?>"><i class="fal fa-fw fa-sign-out-alt"></i><?php echo esc_html__( 'Logout', 'prolancer' ); ?></a>
			</li>
		</ul>
	</div>
</div>