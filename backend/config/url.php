<?php

/**
 * A couple of endpoints need to build a link back into the site (e.g. the
 * password-reset email link, or a redirect to a role's dashboard). Rather than
 * hardcoding the htdocs folder name/alias (which breaks if the project folder
 * is renamed or served from a different path), this derives the site's base
 * URL from the currently-requested script's own path.
 */
function getProjectBaseUrl(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.*)/(pages|backend)/#', $scriptName, $matches)) {
        return $matches[1];
    }
    return '';
}

/**
 * Same as getProjectBaseUrl(), but with the scheme and host prepended --
 * needed anywhere the link has to work outside the browser that requested
 * it, e.g. in an emailed account-setup link, where a root-relative path
 * alone can't be resolved.
 */
function getProjectFullBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . getProjectBaseUrl();
}
