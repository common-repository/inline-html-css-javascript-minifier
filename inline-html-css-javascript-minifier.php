<?php
/*
Plugin Name: Inline HTML, CSS & Javascript Minifier
Plugin URI: https://digitaloutback.co.uk
Description: Minify all front end code (HTML, CSS and Javascript).
Author: Will Abbott
Version: 1.0.2
*/

include( plugin_dir_path( __FILE__ ) . 'ImSettingsPage.php' );

// Set activation
function inline_hcjm_activate() {
    // set transient to display admin notices
    set_transient('inline-hcjml-activated', true, 5);

    // set default values
    $default = [
        'html'       => 1,
        'css'        => 1,
        'javascript' => 1
    ];
    update_option( 'inline_hcjm_options', $default );
}
register_activation_hook( __FILE__, 'inline_hcjm_activate' );

// Admin notice to warn about incompatibility with Varnish cache
add_action( 'admin_notices', 'inline_hcjmh_warning' );

function inline_hcjmh_warning() {
    // check for transient
    if( get_transient('inline-hcjml-activated') ) { ?>
        <div class="notice notice-info is-dismissable">
            <p><?php echo __( 'Inline Html, Css & Javascript minifier activated. Choose what gets minified - ', 'inline-hcjml') . ' <a href="options-general.php?page=inline-html-css-javascript-minifier-settings">'.__('settings page','inline-hcjml') . '</a>.'; ?></p>
        </div>
        <div class="notice notice-warning is-dismissable">
            <p><?php echo __( 'WARNING: Inline Html, Css & Javascript Minifier will not work with Varnish caching','inline-hcjml'); ?></p>
        </div>
        <?php
        delete_transient('inline-hcjml-activated');
    }
}

// Add admin menu
if( is_admin() ) $inline_hcjmgs_page = new ImSettingsPage();

$options = get_option('inline_hcjm_options');

// Buffering and modifying output
function inline_hcjm_start() {

    $options = get_option('inline_hcjm_options');

    ob_start( function($buffer) use ($options) {
        return inline_hcjm_html($buffer, $options);
    });
}
function inline_hcjm_end() {
    @ob_end_flush();
}

if( !is_admin() ) {
    add_action('after_setup_theme', 'inline_hcjm_start');
    add_action('shutdown', 'inline_hcjm_end');
}

// HTML Minifier
function inline_hcjm_html($input,$options = array()) {

    if(trim($input) === "") return $input;

    // Minify HTML
    if( !empty($options['html']) ) {

        $input = preg_replace_callback('#<([^\/\s<>!]+)(?:\s+([^<>]*?)\s*|\s*)(\/?)>#s', function($matches) {
            return '<' . $matches[1] . preg_replace('#([^\s=]+)(\=([\'"]?)(.*?)\3)?(\s+|$)#s', ' $1$2', $matches[2]) . $matches[3] . '>';
        }, str_replace("\r", "", $input));

        $input = preg_replace(
            array(
                // t = text
                // o = tag open
                // c = tag close
                // Keep important white-space(s) after self-closing HTML tag(s)
                '#<(img|input)(>| .*?>)#s',
                // Remove a line break and two or more white-space(s) between tag(s)
                '#(<!--.*?-->)|(>)(?:\n*|\s{2,})(<)|^\s*|\s*$#s',
                '#(<!--.*?-->)|(?<!\>)\s+(<\/.*?>)|(<[^\/]*?>)\s+(?!\<)#s', // t+c || o+t
                '#(<!--.*?-->)|(<[^\/]*?>)\s+(<[^\/]*?>)|(<\/.*?>)\s+(<\/.*?>)#s', // o+o || c+c
                '#(<!--.*?-->)|(<\/.*?>)\s+(\s)(?!\<)|(?<!\>)\s+(\s)(<[^\/]*?\/?>)|(<[^\/]*?\/?>)\s+(\s)(?!\<)#s', // c+t || t+o || o+t -- separated by long white-space(s)
                '#(<!--.*?-->)|(<[^\/]*?>)\s+(<\/.*?>)#s', // empty tag
                '#<(img|input)(>| .*?>)<\/\1>#s', // reset previous fix
                '#(&nbsp;)&nbsp;(?![<\s])#', // clean up ...
                '#(?<=\>)(&nbsp;)(?=\<)#', // --ibid
                // Remove HTML comment(s) except IE comment(s)
                '#\s*<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->\s*|(?<!\>)\n+(?=\<[^!])#s'
            ),
            array(
                '<$1$2</$1>',
                '$1$2$3',
                '$1$2$3',
                '$1$2$3$4$5',
                '$1$2$3$4$5$6$7',
                '$1$2$3',
                '<$1$2',
                '$1 ',
                '$1',
                ""
            ),
        $input);

    }

    // Minify CSS
    if( !empty($options['css']) ) {

        if(strpos($input, ' style=') !== false) {
            $input = preg_replace_callback('#<([^<]+?)\s+style=([\'"])(.*?)\2(?=[\/\s>])#s', function($matches) {
                return '<' . $matches[1] . ' style=' . $matches[2] . inline_hcjm_minify_css($matches[3]) . $matches[2];
            }, $input);
        }
        if(strpos($input, '</style>') !== false) {
          $input = preg_replace_callback('#<style(.*?)>(.*?)</style>#is', function($matches) {
            return '<style' . $matches[1] .'>'. inline_hcjm_minify_css($matches[2]) . '</style>';
          }, $input);
        }

    }

    // Minify Javascript
    if( !empty($options['javascript']) ) {

        if(strpos($input, '</script>') !== false) {
          	$input = preg_replace_callback('#<script(.*?)>(.*?)</script>#is', function($matches) {
            	return '<script' . $matches[1] .'>'. inline_hcjm_minify_js($matches[2]) . '</script>';
          	}, $input);
        }

    }

    return $input;

}


// CSS Minifier => http://ideone.com/Q5USEF + improvement(s)
function inline_hcjm_minify_css($input) {
    if(trim($input) === "") return $input;
    return preg_replace(
        array(
            // Remove comment(s)
            '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')|\/\*(?!\!)(?>.*?\*\/)|^\s*|\s*$#s',
            // Remove unused white-space(s)
            '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/))|\s*+;\s*+(})\s*+|\s*+([*$~^|]?+=|[{};,>~+]|\s*+-(?![0-9\.])|!important\b)\s*+|([[(:])\s++|\s++([])])|\s++(:)\s*+(?!(?>[^{}"\']++|"(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')*+{)|^\s++|\s++\z|(\s)\s+#si',
            // Replace `0(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)` with `0`
            '#(?<=[\s:])(0)(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)#si',
            // Replace `:0 0 0 0` with `:0`
            '#:(0\s+0|0\s+0\s+0\s+0)(?=[;\}]|\!important)#i',
            // Replace `background-position:0` with `background-position:0 0`
            '#(background-position):0(?=[;\}])#si',
            // Replace `0.6` with `.6`, but only when preceded by `:`, `,`, `-` or a white-space
            '#(?<=[\s:,\-])0+\.(\d+)#s',
            // Minify string value
            '#(\/\*(?>.*?\*\/))|(?<!content\:)([\'"])([a-z_][a-z0-9\-_]*?)\2(?=[\s\{\}\];,])#si',
            '#(\/\*(?>.*?\*\/))|(\burl\()([\'"])([^\s]+?)\3(\))#si',
            // Minify HEX color code
            '#(?<=[\s:,\-]\#)([a-f0-6]+)\1([a-f0-6]+)\2([a-f0-6]+)\3#i',
            // Replace `(border|outline):none` with `(border|outline):0`
            '#(?<=[\{;])(border|outline):none(?=[;\}\!])#',
            // Remove empty selector(s)
            '#(\/\*(?>.*?\*\/))|(^|[\{\}])(?:[^\s\{\}]+)\{\}#s'
        ),
        array(
            '$1',
            '$1$2$3$4$5$6$7',
            '$1',
            ':0',
            '$1:0 0',
            '.$1',
            '$1$3',
            '$1$2$4$5',
            '$1$2$3',
            '$1:0',
            '$1$2'
        ),
    $input);
}
// JavaScript Minifier
function inline_hcjm_minify_js($input) {
    if(trim($input) === "") return $input;
    return preg_replace(
        array(
            // Remove comment(s)
            '#\s*("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')\s*|\s*\/\*(?!\!|@cc_on)(?>[\s\S]*?\*\/)\s*|\s*(?<![\:\=])\/\/.*(?=[\n\r]|$)|^\s*|\s*$#',
            // Remove white-space(s) outside the string and regex
            '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/)|\/(?!\/)[^\n\r]*?\/(?=[\s.,;]|[gimuy]|$))|\s*([!%&*\(\)\-=+\[\]\{\}|;:,.<>?\/])\s*#s',
            // Remove the last semicolon
            '#;+\}#',
            // Minify object attribute(s) except JSON attribute(s). From `{'foo':'bar'}` to `{foo:'bar'}`
            '#([\{,])([\'])(\d+|[a-z_][a-z0-9_]*)\2(?=\:)#i',
            // --ibid. From `foo['bar']` to `foo.bar`
            '#([a-z0-9_\)\]])\[([\'"])([a-z_][a-z0-9_]*)\2\]#i'
        ),
        array(
            '$1',
            '$1$2',
            '}',
            '$1$3',
            '$1.$3'
        ),
    $input);

}