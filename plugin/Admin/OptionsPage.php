<?php declare(strict_types=1);
namespace Sgdg\Admin\OptionsPage;

if(!is_admin())
{
	return;
}

function register() : void
{
	add_action('admin_menu', '\\Sgdg\\Admin\\OptionsPage\\add');
}

function add() : void
{
	add_options_page(esc_html__('Google drive gallery', 'skaut-google-drive-gallery'), esc_html__('Google drive gallery', 'skaut-google-drive-gallery'), 'manage_options', 'sgdg', '\\Sgdg\\Admin\\OptionsPage\\html');
}

function html() : void
{
	if (!current_user_can('manage_options'))
	{
		return;
	}

	settings_errors('sgdg_messages');
	echo('<div class="wrap">');
	echo('<h1>' . get_admin_page_title() . '</h1>');
	echo('<form action="options.php" method="post">');
	settings_fields('sgdg');
	do_settings_sections('sgdg');
	submit_button(esc_html__('Save Changes', 'skaut-google-drive-gallery'));
	echo('</form>');
	echo('</div>');
}
