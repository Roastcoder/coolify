<?php

/**
 * Fork-only Roundcube plugin: the StandaloneCoders Mail look (an Outlook-style layout in the
 * brand colors) on top of the Elastic skin, with a top bar and an account menu.
 * Coolify's MailServer service writes it into the webmail container.
 */
class standalonecoders_theme extends rcube_plugin
{
    public function init()
    {
        $this->include_stylesheet('theme.css');
        $this->include_script('theme.js');
        $this->add_hook('render_page', [$this, 'add_top_bar']);
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
