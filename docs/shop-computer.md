# Running Pharmaco on the shop's own computer

For a shop whose internet is unreliable. The shop's computer runs the whole
application and holds the real books, so the counter keeps working with no
internet at all. Whenever it is online it sends a copy to the platform, where
the owner can read it — and only read it — from anywhere.

```
 SHOP (Windows PC or Mac)                  PLATFORM (the hosted site)
 ┌───────────────────────────┐   every 5 min, when online   ┌──────────────────────────────┐
 │ Pharmaco + MySQL          │ ───────── snapshot ────────▶ │ the shop's own database      │
 │ the real books            │                              │ read-only copy               │
 │ counter, stock, closing   │                              │ yourdomain.com/copy/shopname │
 └───────────────────────────┘                              └──────────────────────────────┘
        other shop PCs open it                                     the owner opens it
        over the shop's network                                    from home or a phone
```

Nothing is ever entered into the online copy. It is replaced whole by each
snapshot.

## Part 1 — on the platform (once per shop)

1. **Create an empty database** for the copy in Plesk, for example
   `shop_tayyaba`. Let the main site's database user into it, or create a user
   for it. Each shop needs one of its own: a snapshot replaces everything in
   the database it is loaded into.
2. **Register the shop.** Back office → **Shop copies** → Register a shop. Enter
   the name, a short name for its link, and the database (and the database
   user and password, only if it is a different user from the main site's).
3. **Copy the two lines it shows** — `MIRROR_PUSH_URL` and `MIRROR_PUSH_TOKEN`.
   The token is shown once. "New token" makes another if it is lost.

No subdomain is needed. The copy is opened under the platform's own address,
by the link shown on that page: `https://yourdomain.com/copy/tayyaba`.

Only if you want the shop to have an address of its own as well
(`tayyaba.yourdomain.com`): add that subdomain in Plesk with its document root
set to the same folder as the main site's, give it an SSL certificate, and
enter it when registering.

## Part 2 — on the shop's computer (once)

Use one computer that stays switched on during opening hours.

### Getting the installer

There is no separate download: the installer is built from this project, on a
developer's machine, whenever a shop needs one or an update is ready.

```
shop/package.sh windows     ->  dist/pharmaco-shop-windows.zip   (about 145 MB)
shop/package.sh mac         ->  dist/pharmaco-shop-mac.zip       (about 16 MB)
```

Copy the zip to the shop's computer (USB drive, or a download link).

### Windows

The kit carries PHP, the MariaDB database and the application, so it installs
with no internet and nothing else to set up first.

1. Unzip `pharmaco-shop-windows.zip` anywhere (the Desktop is fine).
2. Open the folder and double-click **Install Pharmaco**. Say Yes when Windows
   asks for administrator rights.
3. Answer its two questions: the values from Part 1 (leave both empty to set
   up the online copy later). It does not ask for a login: the first (back
   office) login is built in, and is not shown on the screen.
4. It finishes by opening `http://localhost:8080` and printing the address
   other computers in the shop should use.

What it does: puts everything in `C:\Pharmaco`, creates the database as a
Windows service (`PharmacoDB`), starts the application whenever the computer
is on, runs the once-a-minute scheduler that sends the online copy, opens
port 8080 on the Windows firewall for the shop's own network, and puts a
**Pharmaco** shortcut on the desktop.

### Mac

The Mac kit carries the application; PHP and MySQL come from Homebrew.

1. If Homebrew is not installed, install it from brew.sh first.
2. Unzip `pharmaco-shop-mac.zip` and double-click **Install Pharmaco**. If
   macOS refuses because it was downloaded, right-click it and choose Open.
3. Answer the same questions as on Windows.

It puts the application in `~/Pharmaco`, creates the `pharmaco` database,
starts it with the Mac, and adds a **Pharmaco** icon to the Applications
folder (drag it to the Dock). The icon opens Pharmaco in the browser and
starts it first if it is not running.

On a Mac that already runs MAMP, the installer uses MAMP's MySQL instead of
installing another. MAMP must be started for Pharmaco to work.

### After installing (either system)

1. Sign in at `http://localhost:8080/back-office/login` with the built-in
   back office login. Change its password straight away (Profile): every
   installation starts with the same one.
2. Create the business, bring in its data and finalise the opening balance as
   usual.
3. **Importing an old POS file needs mdbtools**, which is awkward on Windows.
   Easiest: do the import once on the platform, then move the books to the
   shop's computer with a snapshot (Part 4). Day-to-day work does not need it.

### Other computers in the shop

They open the address the installer printed, for example
`http://192.168.1.20:8080`, in a browser — over the shop's own network, no
internet needed. Give the main computer a fixed IP address on the router so
that address does not change.

### Updating

Build a new kit, copy it to the shop's computer and run **Install Pharmaco**
again. It replaces the application and keeps the books, the settings and the
backups. Update the platform at the same time.

### Checking the online copy is being sent

Open a terminal in the application folder and run:

- Windows: `C:\Pharmaco\runtime\php\php.exe artisan mirror:push`
- Mac: `php artisan mirror:push`

It says "Sent", "Nothing has changed since the last one sent", or "No
internet".

### Removing it

**Uninstall Pharmaco** stops it starting with the computer. It deliberately
leaves the application folder and the database — the books — where they are.

## Part 3 — what each side sees

- **The shop** uses `http://localhost:8080` (or the computer's IP address).
  Everything works without internet: the till, receiving stock, closing the
  day, reports.
- **The owner** opens `https://yourdomain.com/copy/tayyaba` and signs in with
  the **same email and password as in the shop**. A bar across the top says it is
  a read-only copy and how old it is. Anything that would change the books is
  refused with a message.
- **The back office** → Shop copies shows when each shop's last snapshot
  arrived, and the reason if one failed.

## Part 4 — backups, and moving the books

Every snapshot is also a full backup. The last 14 are kept on the shop's
computer in `storage\app\private\mirror\out` (change `MIRROR_KEEP_SNAPSHOTS`
to keep more). Copy that folder to a USB drive now and then.

- **Make a backup without sending it:** `php artisan mirror:push --local`
- **Put the books back** (a replacement computer, after running the installer on it):
  `php artisan mirror:restore C:\path\to\books-2026-11-22-092106.jsonl.gz`
  (on Windows, `php` is `C:\Pharmaco\runtime\php\php.exe`, run from `C:\Pharmaco\app`)
  It replaces everything in that computer's database, and asks first.
- **Move a business that started on the platform to a shop computer:** run
  `php artisan mirror:push --local` on the platform, download the file it
  writes, and restore it on the shop's computer as above. A snapshot is the
  whole installation, so do this only when the platform holds that one shop's
  data; from then on the shop's computer is the one that is used.

## Things to know

- **One place enters, the other reads.** Never enter data on both. The online
  copy cannot be edited for exactly this reason.
- **The copy is as old as the last snapshot.** If the shop has been offline
  since morning, the owner sees the morning. The bar at the top says so.
- **If the shop's computer fails**, the books are safe up to the last snapshot
  that reached the platform, and up to the last file on any USB copy.
- **The Windows kit's built-in web server answers one request at a time.**
  That is fine for a counter or two and the owner. A shop with many computers
  working at once should be moved to a full web server later.
