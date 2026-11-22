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
 │ counter, stock, closing   │                              │ yourdomain.com (sign in)     │
 └───────────────────────────┘                              └──────────────────────────────┘
        other shop PCs open it                                     the owner opens it
        over the shop's network                                    from home or a phone
```

Nothing is ever entered into the online copy. It is replaced whole by each
snapshot.

## Part 1 — on the platform (once per shop)

1. **Register the shop.** Back office → **Shop copies** → Register a shop. A
   name is all it needs.
2. **Copy the two lines it shows** — `MIRROR_PUSH_URL` and `MIRROR_PUSH_TOKEN`.
   The token is shown once. "New token" makes another if it is lost.

There is nothing to create on the server: no database, no subdomain. The
shop's copy is kept in the platform's own database, beside the businesses that
work online, and is marked read-only.

**How the owner sees it:** they sign in at the platform's ordinary sign-in
page with the same email and password as in the shop. The shop's businesses
are simply there, with a bar across the top saying they are a read-only copy
and how old. In the back office they are listed with "Runs in the shop".

Things that follow from sharing one database:

- **Record numbers look different online.** Each shop is given a block of
  numbers, so the shop's transaction 1292 is 100,001,292 in the first shop's
  copy. Bill numbers printed on receipts are not affected.
- **The platform's own new records get large numbers too** once the first copy
  arrives (100,000,000,000 and up), so they can never collide with a shop's.
  Existing records keep their numbers.
- **Logins are matched by email.** A shop's login that does not exist on the
  platform is created with the shop's name and password, never as a platform
  administrator. A login the platform already has is left exactly as it is.
- **If the business's address is taken** on the platform, the copy's gets the
  shop's short name added to it.
- Up to 999 shops fit this way.

A shop can still be given a database of its own instead (tick the box when
registering): for a very large shop, or to keep one entirely apart. That one
is opened at `https://yourdomain.com/copy/<short-name>` and has its own
sign-in. Its database has to exist, or the site's database user has to be
allowed to create it.

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
3. **Importing an old POS file on Windows needs one extra step**, because a
   Windows computer cannot read `.mdb` files itself. Convert the file first on
   a computer that can (a Mac or Linux machine with this project and mdbtools):

   ```
   php artisan oldpos:convert "posdbase.mdb"
   ```

   It writes `posdbase.pharmaco.json` beside it. Copy that file to the shop's
   computer and upload it on the import page exactly as the `.mdb` would have
   been. It holds only the product, supplier and customer lists.

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

The bar at the top of every screen says where things stand: "Online · synced",
"Online · sync pending" (with a **Sync now** button), or "Offline". Hovering
over it gives the reason when something is wrong.

To send one now and see exactly what happens, run these two commands.

**Windows** — Start → type `cmd` → right-click Command Prompt → Run as
administrator, then:

```
cd C:\Pharmaco\app
C:\Pharmaco\runtime\php\php.exe artisan mirror:push --force
```

**Mac** — in Terminal:

```
cd ~/Pharmaco/app
php artisan mirror:push --force
```

What it prints, and what to do:

| It says | Meaning |
|---|---|
| `Sent. The online copy is up to date.` | Working. The business shows on the platform, read-only. |
| `Not sent: MIRROR_PUSH_URL and MIRROR_PUSH_TOKEN are not set` | The two values from Part 1 are missing from the `.env` file (`C:\Pharmaco\app\.env`, or `~/Pharmaco/app/.env`). |
| `The platform could not be reached` + a reason | "Could not resolve host": the address is mistyped, or there is no internet. "SSL certificate": run a newer installer, which carries the certificates. |
| `The platform did not accept the snapshot` + a reason | "token is not recognised": the token is wrong; make a new one under Shop copies. Anything else: send the reason to whoever looks after the platform. |

`--force` sends even when nothing has changed; without it, unchanged books
are not sent twice.

### Removing it

**Uninstall Pharmaco** stops it starting with the computer. It deliberately
leaves the application folder and the database — the books — where they are.

## Part 3 — what each side sees

- **The shop** uses `http://localhost:8080` (or the computer's IP address).
  Everything works without internet: the till, receiving stock, closing the
  day, reports.
- **The owner** signs in at the platform's ordinary sign-in page with the
  **same email and password as in the shop**. A bar across the top says it is
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
