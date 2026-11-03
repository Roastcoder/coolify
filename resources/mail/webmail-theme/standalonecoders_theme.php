<?php

/**
 * Fork-only Roundcube plugin: the StandaloneCoders Mail look (an Outlook-style layout in the
 * brand colors) on top of the Elastic skin. Coolify's MailServer service writes it into the webmail container.
 */
class standalonecoders_theme extends rcube_plugin
{
    public function init()
    {
        $this->include_stylesheet('theme.css');
    }
}
