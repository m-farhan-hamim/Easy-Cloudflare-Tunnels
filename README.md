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

The very first time you run it, psbdx asks how you'd like to navigate its
menus — see **Navigation styles** below. After that, it opens the
interactive menu:

- **Create a new tunnel**
  - **Quick mode** — no domain required. Just give the local port your app
    runs on and psbdx starts a tunnel with an instant `*.trycloudflare.com`
    address.
  - **Own domain** — log in to your Cloudflare account once (opens a
    browser link to authorize), then give a subdomain + your domain
    (e.g. `app` + `example.com`) and the port. psbdx creates the tunnel,
    writes the config, and points the DNS record at it automatically.
- **Manage existing tunnels** — list, start (foreground or
  background), stop, check reachability, view logs, copy the public URL,
  or delete saved tunnels. See **Running in the background** below.
- **Manage domains** — see which hostnames are in use, (re)connect your
  Cloudflare account, or route another subdomain to an existing tunnel.
- **Manage start commands** — turn any saved tunnel into a one-word
  command (see below).
- **Add an existing tunnel manually** — if you already created/run a
  tunnel yourself (see "Existing tunnels" below), tell psbdx how it's
  started and it'll track it from then on, no auto-detection needed.
- **Settings** — switch navigation styles, toggle colored output, or
  back up / restore your saved tunnels and commands as a single JSON
  file.

### Navigation styles

psbdx supports two ways to move through its menus, chosen once on first
run (and changeable anytime from **Settings**):

- **Number navigation** — the classic style. Type the number next to an
  option and press Enter.
- **Arrow navigation** — move a `❯` cursor with the Up/Down arrow keys
  (the ones right above Termux's on-screen keyboard work great) and press
  Enter to pick. A digit key still jumps straight to that option, and `q`
  cancels back to number navigation for that one prompt.

If arrow navigation isn't usable in your terminal (e.g. input is being
piped in from a script), psbdx quietly falls back to number navigation
instead of getting stuck.

### Running in the background

From **Manage existing tunnels**, pick a tunnel and choose **Start it in
the background** to launch it detached, so it keeps running after you
leave the menu (or close the app). psbdx tracks its process id and logs:

- **View recent logs** tails the last lines of that run
- **Check if it's reachable** sends a quick HTTP request to confirm it's
  actually serving traffic
- **Copy its URL to clipboard** (via `termux-clipboard-set` on Termux)
- **Stop its background run** sends it a clean shutdown signal

For quick tunnels, psbdx watches the log for the `*.trycloudflare.com`
URL cloudflared prints on startup and shows it to you (and copies it to
the clipboard on Termux) as soon as it appears.

### Existing tunnels you created outside psbdx

If you already made tunnels by hand with `cloudflared`, psbdx tries to
auto-detect them from your Cloudflare account (main menu → "Import").
Auto-detection depends on `cloudflared tunnel list -o json` returning
clean, parseable output for your account, which isn't always reliable —
different cloudflared versions, tokens vs. cert-based logins, and
multi-account setups can all cause it to find nothing even though your
tunnel is real and working.

If that happens, don't fight it — use **"Add an existing tunnel
manually"** from the main menu instead. You'll be asked for:

- a friendly name and the local port it forwards to
- its hostname, if any
- how you normally start it:
  - **named tunnel** — if you run it with `cloudflared tunnel run <name>`
    (give the tunnel name, and optionally its ID and config.yml path)
  - **token** — if it's a tunnel created in the Zero Trust dashboard and
    started with `cloudflared tunnel run --token <token>` (paste the
    token; it's stored in plain text at `~/.psbdx-data/data.json`, the
    same way it would be in a shell script, so treat it like a password)

Once added, it behaves exactly like any tunnel created through psbdx —
start it, delete it, or give it a one-word start command.

### One-word start commands

After creating (or from *Manage tunnels* / *Manage start commands*), psbdx
can offer a custom command name, e.g. `mysite`. From then on, just type:

```bash
mysite
```

...and that tunnel starts immediately — no menus.

Add `-bg` to the end of any start command to run it as a background
service instead — it launches detached and hands your terminal back:

```bash
mysite -bg
```

Use *Manage tunnels* to view its logs or stop it later. Commands made
before this feature existed pick up `-bg` automatically after
`psbdx update`.

### Other commands

```bash
psbdx start <name-or-id>   # start a saved tunnel directly
psbdx start <name-or-id> -bg   # ...or run it in the background
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
- `~/.psbdx-data/data.json` — your saved tunnels, start commands, and
  settings (navigation style, color preference)
- `~/.psbdx-data/logs/` — output from tunnels started in the background
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
