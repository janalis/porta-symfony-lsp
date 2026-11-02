# Symfony language server

The official Symfony language server
([symfony/language-tools](https://github.com/symfony/language-tools)) for the
files around your PHP: Twig, YAML, XML, JSON and dotenv — and PHP itself when
neither PHP server ([phpantom](../../phpantom/docs/README.md) nor
[phpactor](../../phpactor/docs/README.md)) is enabled. Bundled with Porta and
off by default — turn it on under **Settings → Plugins → symfony-lsp**.

![The symfony-lsp page in Settings → Plugins](/screenshots/plugin-symfony-lsp.svg)

## Installing the server

The server ships only as a binary on GitHub Releases, so Porta installs it for
you: press **Install** beside **symfony-lsp** under **Tools** on this plugin's
settings page, or run **Plugins: Install Missing Tools** from the palette. It
downloads the release for your machine, verifies its checksum, checks that it
runs, and puts it in `~/.porta/bin`, where every project shares it. A toast
follows each step — resolving, downloading with a percentage, verifying,
extracting, checking the binary.

Once a copy is there, the same button reads **Update**, and it **compares
before it downloads**: it resolves the latest release, asks the installed
binary its version, and says "already up to date" rather than fetching ~9 MB
again. The Welcome card is not done until the server is actually there —
pressing it enables the plugin *and* installs it.

Porta checks for a newer release in the background with every other tool —
once a day by default; **Check tools for updates** under **Settings → General**
makes it weekly or turns it off.

Installing needs `curl` and `tar` on your `PATH`, and honours `GITHUB_TOKEN` /
`GH_TOKEN` if GitHub rate-limits the release lookup.

## Runtime indexing

Without it the server reads your files statically. With **Runtime indexing**
on it also inspects the application itself, which means **executing your
project's PHP** — so the setting is the workspace-trust decision, and it is off
until you make it. **PHP command** is what the application is run with
(`symfony php`, for instance), and **Symfony environment** which environment it
boots in.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| symfony-lsp binary | empty | The server to run; empty tries your `PATH`, then `~/.porta/bin` |
| Languages | `php, twig, yaml, xml, json, dotenv` | Comma-separated languages to serve; `*` for every language. PHP is served only when no PHP server claims it |
| Runtime indexing | off | Let the server execute your application's PHP to index it |
| PHP command | `php` | How the application is run when inspected |
| Symfony environment | `dev` | The environment the application boots in |
