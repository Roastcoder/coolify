<?php

/**
 * Fork-only Roundcube plugin: the StandaloneCoders Mail look (an Outlook-style layout in the
 * brand colors) on top of the Elastic skin, with a top bar and an account menu.
 * Coolify's MailServer service writes it into the webmail container.
 */
class standalonecoders_theme extends rcube_plugin
{
    /** "Remember me" keeps the sign-in cookies this long; the server keeps sessions as long (session_lifetime). */
    const REMEMBER_SECONDS = 2592000;

    const REMEMBER_COOKIE = 'sc_remember';

    public function init()
    {
        $this->include_stylesheet('theme.css');
        $this->include_script('theme.js');
        $this->add_hook('render_page', [$this, 'add_top_bar']);
        $this->add_hook('render_page', [$this, 'add_login_panel']);
        $this->add_hook('login_after', [$this, 'remember_login']);
        $this->add_hook('logout_after', [$this, 'forget_login']);

        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $this->keep_cookies();
        }
    }

    public function remember_login($args)
    {
        if (rcube_utils::get_input_value('_remember', rcube_utils::INPUT_POST)) {
            rcube_utils::setcookie(self::REMEMBER_COOKIE, '1', time() + self::REMEMBER_SECONDS);
            $this->keep_cookies();
        }

        return $args;
    }

    public function forget_login($args)
    {
        rcube_utils::setcookie(self::REMEMBER_COOKIE, '', time() - 60);

        return $args;
    }

    /**
     * Roundcube sends its session and auth cookies without an expiry, so they end with the browser.
     * For a remembered sign-in, give them one just before the headers go out.
     */
    private function keep_cookies()
    {
        header_register_callback(function () {
            $cookies = array_filter(headers_list(), fn ($header) => stripos($header, 'Set-Cookie:') === 0);
            if (!$cookies) {
                return;
            }

            header_remove('Set-Cookie');
            $expires = gmdate('D, d M Y H:i:s', time() + self::REMEMBER_SECONDS).' GMT';
            foreach ($cookies as $header) {
                if (preg_match('/^Set-Cookie:\s*roundcube_sess(id|auth)=(?!-del-)/i', $header) && stripos($header, 'expires=') === false) {
                    $header .= '; expires='.$expires.'; Max-Age='.self::REMEMBER_SECONDS;
                }
                header($header, false);
            }
        });
    }

    /** Sign-in page: a brand panel next to the form. theme.js adds the "Remember me" box. */
    public function add_login_panel($args)
    {
        if (rcmail::get_instance()->task !== 'login') {
            return $args;
        }

        $panel = '<aside id="sc-login-brand" aria-hidden="true">'
            .'<div class="sc-login-brand-top"><img src="plugins/standalonecoders_theme/logo.svg" alt="" width="36" height="36"><span>StandaloneCoders Mail</span></div>'
            .'<div class="sc-login-brand-copy"><h2>Your business email, in one place.</h2>'
            .'<ul><li>Read and send from any browser</li><li>Works with Outlook, Apple Mail and phone apps</li><li>Encrypted connections and signed mail</li></ul></div>'
            .'<p class="sc-login-brand-foot">&copy; '.date('Y').' StandaloneCoders</p>'
            .'</aside>';

        $content = preg_replace('/<body\b[^>]*>/i', '$0'.str_replace(['\\', '$'], ['\\\\', '\\$'], $panel), $args['content'], 1);
        $heading = '<h2 class="sc-login-title">Sign in</h2><p class="sc-login-sub">Use your full email address and password.</p>';
        $content = preg_replace('/<form\b[^>]*id="login-form"[^>]*>/i', '$0'.$heading, $content, 1);
        $args['content'] = $content;

        return $args;
    }

    /** Puts the top bar with the account menu at the start of every full page. */
    public function add_top_bar($args)
    {
        $rcmail = rcmail::get_instance();
        if (empty($_SESSION['user_id']) || $rcmail->output->framed || $rcmail->task === 'login') {
            return $args;
        }

        $email = (string) $rcmail->get_user_name();
        $identity = $rcmail->user->get_identity();
        $name = trim((string) ($identity['name'] ?? '')) ?: strstr($email, '@', true);
        $initials = strtoupper(implode('', array_map(
            fn ($part) => mb_substr($part, 0, 1),
            array_slice(preg_split('/[\s._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name], 0, 2)
        )));
        $hue = crc32($email) % 360;

        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $links = [
            'Change password' => $rcmail->url(['_task' => 'settings', '_action' => 'plugin.password']),
            'Signature and name' => $rcmail->url(['_task' => 'settings', '_action' => 'identities']),
            'Settings' => $rcmail->url(['_task' => 'settings']),
        ];
        $logout = $rcmail->url(['_task' => 'logout', '_token' => $rcmail->get_request_token()]);

        $avatar = fn ($class) => '<span class="sc-avatar '.$class.'" style="--sc-hue:'.$hue.'" aria-hidden="true">'.$e($initials).'</span>';
        $items = '';
        foreach ($links as $label => $url) {
            $items .= '<a role="menuitem" href="'.$e($url).'">'.$e($label).'</a>';
        }

        $bar = '<header id="sc-topbar">'
            .'<a class="sc-brand" href="'.$e($rcmail->url(['_task' => 'mail'])).'">'
            .'<img src="plugins/standalonecoders_theme/logo.svg" alt="" width="26" height="26"><span>StandaloneCoders Mail</span></a>'
            .'<div class="sc-account">'
            .'<button type="button" class="sc-account-button" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu">'.$avatar('').'</button>'
            .'<div class="sc-menu" role="menu" hidden>'
            .'<div class="sc-menu-head">'.$avatar('sc-avatar-lg').'<div><strong>'.$e($name).'</strong><small>'.$e($email).'</small></div></div>'
            .$items
            .'<a role="menuitem" class="sc-signout" href="'.$e($logout).'">Sign out</a>'
            .'</div></div></header>';

        $args['content'] = preg_replace('/<body\b[^>]*>/i', '$0'.str_replace(['\\', '$'], ['\\\\', '\\$'], $bar), $args['content'], 1);

        return $args;
    }
}
