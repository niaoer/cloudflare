<?php
/*
Plugin Name: CloudFlare
Plugin URI: http://www.cloudflare.com/wiki/CloudFlareWordPressPlugin
Description: CloudFlare 插件将您的博客与 CloudFlare 平台完美整合。<strong>注意</strong>：汉化版插件无法自动更新，请访问<a href="http://www.niaoer.org/535.html" href="_blank">鸟儿的博客</a>并手动下载后更新。
Version: 1.3.12
Author: Ian Pye, Jerome Chen, James Greene, Simon Moore (CloudFlare Team)
License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or modify 
it under the terms of the GNU General Public License as published by 
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful, 
but WITHOUT ANY WARRANTY; without even the implied warranty of 
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the 
GNU General Public License for more details. 

You should have received a copy of the GNU General Public License 
along with this program; if not, write to the Free Software 
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA 

Plugin adapted from the Akismet WP plugin.

*/	

define('CLOUDFLARE_VERSION', '1.3.12');
define('CLOUDFLARE_API_URL', 'https://www.cloudflare.com/api_json.html'); 

require_once("ip_in_range.php");

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo "请勿直接调用！";
	exit;
}

function cloudflare_init() {
    global $cf_api_host, $cf_api_port, $is_cf;

    $cf_api_host = "ssl://www.cloudflare.com";
    $cf_api_port = 443;
    
    $is_cf = (isset($_SERVER["HTTP_CF_CONNECTING_IP"]))? TRUE: FALSE;    

    if (strpos($_SERVER["REMOTE_ADDR"], ":") === FALSE) {
        $cf_ip_ranges = array("173.245.48.0/20","103.21.244.0/22","103.22.200.0/22","103.31.4.0/22","141.101.64.0/18","108.162.192.0/18","190.93.240.0/20","188.114.96.0/20","197.234.240.0/22","198.41.128.0/17","162.158.0.0/15","199.27.128.0/21");
        // IPV4: Update the REMOTE_ADDR value if the current REMOTE_ADDR value is in the specified range.
        foreach ($cf_ip_ranges as $range) {
            if (ipv4_in_range($_SERVER["REMOTE_ADDR"], $range)) {
                if ($_SERVER["HTTP_CF_CONNECTING_IP"]) {
                    $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
                }
                break;
            }
        }        
    }
    else {
        $cf_ip_ranges = array("2400:cb00::/32", "2606:4700::/32", "2803:f800::/32");
        $ipv6 = get_ipv6_full($_SERVER["REMOTE_ADDR"]);
        foreach ($cf_ip_ranges as $range) {
            if (ipv6_in_range($ipv6, $range)) {
                if ($_SERVER["HTTP_CF_CONNECTING_IP"]) {
                    $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
                }
                break;
            }
        }
    }
        
    // Let people know that the CF WP plugin is turned on.
    if (!headers_sent()) {
        header("X-CF-Powered-By: WP " . CLOUDFLARE_VERSION);
    }
    add_action('admin_menu', 'cloudflare_config_page');
    cloudflare_admin_warnings();
}
add_action('init', 'cloudflare_init',1);

function cloudflare_admin_init() {
    
}

add_action('admin_init', 'cloudflare_admin_init');

function cloudflare_config_page() {
	if ( function_exists('add_submenu_page') ) {
		add_submenu_page('plugins.php', __('CloudFlare 设置'), __('CloudFlare'), 'manage_options', 'cloudflare', 'cloudflare_conf');
    }
}

function load_cloudflare_keys () {
    global $cloudflare_api_key, $cloudflare_api_email;
    if (!$cloudflare_api_key) {
        $cloudflare_api_key = get_option('cloudflare_api_key');
    }
    if (!$cloudflare_api_email) {
        $cloudflare_api_email = get_option('cloudflare_api_email');
    }
}

function cloudflare_conf() {
    if ( function_exists('current_user_can') && !current_user_can('manage_options') )
        die(__('哎呀，出错了！'));
    global $cloudflare_api_key, $cloudflare_api_email, $is_cf;
    global $wpdb;
    
    $messages = array(
        'dev_mode_on' => array('color' => '2d2', 'text' => __('开发模式已开启！')),
        'dev_mode_off' => array('color' => 'aa0', 'text' => __('开发模式已关闭！'))
    );

    // get raw domain - may include www.
    $urlparts = parse_url(site_url());
    $raw_domain = $urlparts["host"];

    $curl_installed = function_exists('curl_init');

	$domain = null;

    if ($curl_installed) {
        // Attempt to get the matching host from CF
        $domain = get_domain($cloudflare_api_key, $cloudflare_api_email, $raw_domain);
        
        // If not found, default to pulling the domain via client side.
        if (is_wp_error($domain)) {
        	$messages['get_domain_failed'] = array('color' => 'FFA500', 'text' => __('无法通过 CloudFlare API 获取域名信息，出错信息： ' . $domain->get_error_message()));
            $ms[] = 'get_domain_failed';
            $domain = null;
        }
    }
    
    if ($domain == null) {
    	$domain = $raw_domain;
    	$messages['domain_not_found'] = array('color' => 'FFA500', 'text' => __('CloudFlare 未找到您的域名信息，请确认您的域名地址为 ' . $domain));
    	$ms[] = 'domain_not_found';    
    }
    
    define ("THIS_DOMAIN",  $domain);

    $db_results = array();
               
	if ( isset($_POST['submit']) 
         && check_admin_referer('cloudflare-db-api','cloudflare-db-api-nonce') ) {
        
		if ( function_exists('current_user_can') && !current_user_can('manage_options') ) {
			die(__('哎呀，出错了！'));
        }

		$key = $_POST['key'];
		$email = $_POST['email'];
        $dev_mode = esc_sql($_POST["dev_mode"]);
        
		if ( empty($key) ) {
			$key_status = 'empty';
			$key_message = 'API Key 已清除。';
			delete_option('cloudflare_api_key');
		} else {
			$key_message = '您的 API Key 已验证。';
			update_option('cloudflare_api_key', esc_sql($key));
            update_option('cloudflare_api_key_set_once', "TRUE");
        }

		if ( empty($email) || !is_email($email) ) {
			$email_status = 'empty';
			$email_message = 'Email 已清除。';
			delete_option('cloudflare_api_email');
		} else {
			$email_message = '您的 Email 已验证。';
			update_option('cloudflare_api_email', esc_sql($email));
            update_option('cloudflare_api_email_set_once', "TRUE");
        }

        if ($curl_installed) {
            if ($key != "" && $email != "") {
                
                $result = set_dev_mode(esc_sql($key), esc_sql($email), THIS_DOMAIN, $dev_mode);
                
                if (is_wp_error($result)) {
					error_log($result->get_error_message());
					$messages['set_dev_mode_failed'] = array('color' => 'FF0000', 'text' => __('无法设置开发模式，出错信息： ' . $result->get_error_message()));
            		$ms[] = 'set_dev_mode_failed';
				}
				else {
					if ($dev_mode && $result->result == 'success') {
                    	$ms[] = 'dev_mode_on';
                	}
                	else if (!$dev_mode && $result->result == 'success') {
                    	$ms[] = 'dev_mode_off';
                	}
				}
                
                
            }
        }
    }
    ?>
    <div class="wrap">

    <?php if ($is_cf) { ?>
        <h3>您正在使用 CloudFlare！</h3>
    <?php } ?>
    
    <?php if ( !empty($_POST['submit'] )) { ?>
    <div id="message" class="updated fade"><p><strong><?php _e('设置已更新') ?></strong></p></div>
    <?php } ?>
    <?php if ($ms) { foreach ( $ms as $m ) { ?>
    <div id="message" class="updated fade"><p style="padding: .5em; color: #<?php echo $messages[$m]['color']; ?>; font-weight: bold;"><?php echo $messages[$m]['text']; ?></p></div>
    <?php } } ?>

    <h4><?php _e('CLOUDFLARE WORDPRESS 插件：'); ?></h4>
        <?php //    <div class="narrow"> ?>

CloudFlare 是一款专门为 WordPress 开发的插件，它的功能主要有：
<ol>
<li>还原访问者的原始 IP 地址</li>
<li>保护您的网站不受垃圾信息干扰</li>
</ol>

<h4>适用版本：</h4>

兼容 WordPress 2.8.6 至最新版。如果您正在使用的版本低于 2.8.6，将无法使用本插件，建议您升级到最新版本的 WordPress。

<h4>说明：</h4>

<ol>
<li>由于 CloudFlare 使用反向代理的方式导致访问者的真实 IP 地址变成了 CloudFlare 的 IP 地址，本插件能够获取网站访问者的原始 IP 地址。</li>
 
<li>如果您将某条评论标记为垃圾内容，那么这条评论内容将会发送到 CloudFlare，帮助您的网站得到更好的保护。</li>

<li>我们强烈推荐 CloudFlare 用户安装此插件。</li>

<li>注意：本插件与 Akismet 和 W3 Total Cache 兼容，您可以放心地使用它们。</li> 

</ol>

<h4>更多：</h4>

<a href="http://www.cloudflare.com/">CloudFlare 官方网站</a>

    <?php 
        if ($curl_installed) {
            $dev_mode = get_dev_mode_status($cloudflare_api_key, $cloudflare_api_email, THIS_DOMAIN);
            if (is_wp_error($dev_mode)) {
            	$messages['get_dev_mode_failed'] = array('color' => 'aa0', 'text' => __('无法获取开发模式状态，出错信息： ' . $dev_mode->get_error_message()));
            	$ms[] = 'get_dev_mode_failed';
            }
        } 
    ?>

    <hr />

    <form action="" method="post" id="cloudflare-conf">
    <?php wp_nonce_field('cloudflare-db-api','cloudflare-db-api-nonce'); ?>
    <?php if (get_option('cloudflare_api_key') && get_option('cloudflare_api_email')) { ?>
    <?php } else { ?> 
        <p><?php printf(__('输入您的 API Key，获取 API Key 请登陆 <a href="%1$s">CloudFlare</a> 后进入 \'Account\'.'), 'https://www.cloudflare.com/my-account.html'); ?></p>
    <?php } ?>
    <h3><label for="key"><?php _e('CloudFlare API Key'); ?></label></h3>
    <p>
    	<input id="key" name="key" type="text" size="50" maxlength="48" value="<?php echo get_option('cloudflare_api_key'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> (<?php _e('<a href="https://www.cloudflare.com/my-account.html">获取 API Key</a>'); ?>)
    </p>
    <?php if (isset($key_message)) echo sprintf('<p>%s</p>', $key_message); ?>
    
    <h3><label for="email"><?php _e('CloudFlare 账户邮箱'); ?></label></h3>
    <p>
    	<input id="email" name="email" type="text" size="50" maxlength="48" value="<?php echo get_option('cloudflare_api_email'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> (<?php _e('<a href="https://www.cloudflare.com/my-account.html">获取邮箱地址</a>'); ?>)
    </p>
    <?php if (isset($key_message)) echo sprintf('<p>%s</p>', $key_message); ?>
    
    <h3><label for="dev_mode"><?php _e('开发模式'); ?></label> <span style="font-size:9pt;">(<a href="https://support.cloudflare.com/entries/22280726-what-does-cloudflare-development-mode-mean" " target="_blank">这是什么？</a>)</span></h3>

    <?php if ($curl_installed) { ?>
    <div style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;">
    <input type="radio" name="dev_mode" value="0" <?php if ($dev_mode == "off") echo "checked"; ?>> 关闭
    <input type="radio" name="dev_mode" value="1" <?php if ($dev_mode == "on") echo "checked"; ?>> 开启
    </div>
    <?php } else { ?>
    抱歉，CloudFlare 检测到您的服务器未安装 cURL 模块，无法切换到“开发模式”，请联系空间服务商或自行安装该模块。
    <?php } ?>
    
    </p>
    <p class="submit"><input type="submit" name="submit" value="<?php _e('保存设置 &raquo;'); ?>" /></p>
    </form>

        <?php //    </div> ?>
    </div>
    <?php
}

function cloudflare_admin_warnings() {
    
    global $cloudflare_api_key, $cloudflare_api_email;
    load_cloudflare_keys();    
}

// Now actually allow CF to see when a comment is approved/not-approved.
function cloudflare_set_comment_status($id, $status) {
    global $cf_api_host, $cf_api_port, $cloudflare_api_key, $cloudflare_api_email; 
    if (!$cf_api_host || !$cf_api_port) {
        return;
    }
    load_cloudflare_keys();
    if (!$cloudflare_api_key || !$cloudflare_api_email) {
        return;
    }

    // ajax/external-event.html?email=ian@cloudflare.com&t=94606855d7e42adf3b9e2fd004c7660b941b8e55aa42d&evnt_v={%22dd%22:%22d%22}&evnt_t=WP_SPAM
    $comment = get_comment($id);
    $value = array("a" => $comment->comment_author, 
                   "am" => $comment->comment_author_email,
                   "ip" => $comment->comment_author_IP,
                   "con" => substr($comment->comment_content, 0, 100));
    $url = "/ajax/external-event.html?evnt_v=" . urlencode(json_encode($value)) . "&u=$cloudflare_api_email&tkn=$cloudflare_api_key&evnt_t=";
     
    // If spam, send this info over to CloudFlare.
    if ($status == "spam") {
        $url .= "WP_SPAM";
        $fp = @fsockopen($cf_api_host, $cf_api_port, $errno, $errstr, 30);
        if ($fp) {
            $out = "GET $url HTTP/1.1\r\n";
            $out .= "Host: www.cloudflare.com\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            $res = "";
            while (!feof($fp)) {
                $res .= fgets($fp, 128);
            }
            fclose($fp);
        }
    }
}

add_action('wp_set_comment_status', 'cloudflare_set_comment_status', 1, 2);

function get_dev_mode_status($token, $email, $zone) {
    
    $fields = array(
        'a'=>"zone_load",
        'tkn'=>$token,
        'email'=>$email,
        'z'=>$zone
    );
    
    $result = cloudflare_curl(CLOUDFLARE_API_URL, $fields, true);
    
    if (is_wp_error($result)) {
    	error_log($result->get_error_message());
    	return $result;
	}
	
    if ($result->response->zone->obj->zone_status_class == "status-dev-mode") {
        return "on";
    }

    return "off";
}

function set_dev_mode($token, $email, $zone, $value) {
    
    $fields = array(
    	'a'=>"devmode",
        'tkn'=>$token,
        'email'=>$email,
        'z'=>$zone,
        'v'=>$value
	);
	
    $result = cloudflare_curl(CLOUDFLARE_API_URL, $fields, true);
    
    if (is_wp_error($result)) {
    	error_log($result->get_error_message());
		return $result;
	}
	
	return $result;
}

function get_domain($token, $email, $raw_domain) {
    
    $fields = array(
    	'a'=>"zone_load_multi",
        'tkn'=>$token,
        'email'=>$email
    );

	$result = cloudflare_curl(CLOUDFLARE_API_URL, $fields, true);
	
	if (is_wp_error($result)) {
		error_log($result->get_error_message());
		return $result;
	}
	
    $zone_count = $result->response->zones->count;
    if ($zone_count > 0) {
        for ($i = 0; $i < $zone_count; $i++) {
            $zone_name = $result->response->zones->objs[$i]->zone_name;
            if (strpos($raw_domain, $zone_name) !== FALSE){
                return $zone_name;
            }
        }
    }
    
    return null;
}

/**
* @param $url		string		the URL to curl
* @param $fields	array		an associative array of arguments for POSTing
* @param $json 		boolean		attempt to decode response as JSON
* 
* @returns WP_ERROR|string|object in the case of an error, otherwise a $result string or JSON object
*/
function cloudflare_curl($url, $fields = array(), $json = true) {

	$ch = curl_init();
	
    curl_setopt($ch,CURLOPT_URL,$url);

	if (!empty($fields)) {
	
		$fields_string = '';
		
		foreach($fields as $key=>$value) { 
        	$fields_string .= $key.'='.$value.'&';
    	}
    	rtrim($fields_string,'&');
	
		curl_setopt($ch,CURLOPT_POST,count($fields));
    	curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
	}

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    
    $result = curl_exec($ch);
    
    if ($result == false) {
    	$curl_error = curl_error($ch);
    	return new WP_Error('curl', sprintf('cURL 请求失败，错误信息： %s', $curl_error));
    }
    
    if ($json == true) {
    	$result = json_decode($result);
    	// not a perfect test, but better than nothing perhaps
    	if ($result == null) {
    		return new WP_Error('json_decode', sprintf('无法解析 JSON 内容。'), $result);
    	}
    }
    
    curl_close($ch);
    
    return $result;
}
?>
