# Easy Cloudflare Tunnels (psbdx)

A simple, guided CLI for managing [Cloudflare Tunnels](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/)
— built for **Termux** (Android) and regular **Linux** terminals.

No YAML wrangling, no memorizing `cloudflared` flags. Answer a couple of
plain-English questions (port number, subdomain) and psbdx does the rest.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/m-farhan-hamim/Easy-Cloudflare-Tunnels/main/install.sh | bash
```

This works the same way on Termux and on Linux. It will:

- install `git`, `python3`, `curl` if missing
- clone this repo to `~/.psbdx` (as a git checkout, so updates are just a `git pull`)
- install `cloudflared` (via `pkg` on Termux, or a direct binary download on Linux)
- add a `psbdx` command to your `PATH`

Open a new terminal session afterwards if `psbdx` isn't found right away.

## Usage

```bash
psbdx cloud
```

This opens the interactive menu:

- **Create a new tunnel**
  - **Quick mode** — no domain required. Just give the local port your app
    runs on and psbdx starts a tunnel with an instant `*.trycloudflare.com`
    address.
  - **Own domain** — log in to your Cloudflare account once (opens a
    browser link to authorize), then give a subdomain + your domain
    (e.g. `app` + `example.com`) and the port. psbdx creates the tunnel,
    writes the config, and points the DNS record at it automatically.
- **Manage existing tunnels** — list, start, or delete saved tunnels.
- **Manage domains** — see which hostnames are in use, (re)connect your
  Cloudflare account, or route another subdomain to an existing tunnel.
- **Manage start commands** — turn any saved tunnel into a one-word
  command (see below).

### One-word start commands

After creating (or from *Manage tunnels* / *Manage start commands*), psbdx
can offer a custom command name, e.g. `mysite`. From then on, just type:

```bash
mysite
```

...and that tunnel starts immediately — no menus.

### Other commands

```bash
psbdx start <name-or-id>   # start a saved tunnel directly
psbdx update                # pull the latest version of psbdx
psbdx uninstall              # remove psbdx from this device
psbdx help                   # show usage
```

On Termux, a reminder about `psbdx cloud` is added to your login MOTD
message so it's easy to remember the command is available (Termux doesn't
have a shared help registry that third-party tools can plug into, so this
is the closest equivalent).

## Data & files

- `~/.psbdx` — the installed program itself (git checkout, updated via `psbdx update`)
- `~/.psbdx-data/data.json` — your saved tunnels and start commands
- `~/.cloudflared/` — cloudflared's own config, credentials, and login cert

Uninstalling removes the first two. It does **not** delete your tunnels or
DNS records from Cloudflare itself — remove those from the
[Cloudflare Zero Trust dashboard](https://one.dash.cloudflare.com/) if you
want them gone too.

## Requirements

- Python 3.7+
- git, curl
- A Cloudflare account (only needed for "Own domain" mode)

## Uninstall

```bash
psbdx uninstall
```

or, if the command isn't on your PATH anymore:

```bash
bash ~/.psbdx/uninstall.sh
```
