# Temporary UCCHR sharing for groupmate testing

This uses a protected Cloudflare Quick Tunnel. It gives the current PC a
temporary HTTPS address; it does **not** move PHP or MariaDB to Cloudflare.
Only share it with groupmates you trust to access the HR data they are
authorized to see. Use individual email addresses, never a wildcard domain.

## Start

1. Connect this PC to the internet. Start the existing UCCHR server by
   double-clicking `start-server.cmd` in this folder. Leave that window open.
   Confirm `http://localhost:8080/launch.html` works on the PC.
2. Double-click `share-with-groupmates.cmd` in this folder. Enter the
   groupmates' email addresses, separated by commas. Alternatively, in
   PowerShell run:

   ```powershell
   cd "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\share-with-groupmates.ps1"
   ```

3. The tunnel window will show a random `https://...trycloudflare.com` URL.
   Add `/launch.html` to it. Send that full link to the allowed groupmates.
   They enter their allowed email address and Cloudflare's one-time email code,
   then use their own existing UCCHR login. The email gate is *in addition to*
   the application's login, not a replacement for it.
4. Test from a groupmate's phone on mobile data, not only the school Wi-Fi.
   Never send passwords, database credentials, or the ESP32 device secret.

Example message to your groupmates (replace the URL with the **new** one shown
in your tunnel window):

> Open https://YOUR-RANDOM-NAME.trycloudflare.com/launch.html. Use the email
> address I allowed for you, enter the one-time code Cloudflare emails you,
> then sign in to your own UCCHR account. This test link works only while my
> computer and the server are running. Please do not forward it.

## Stop or troubleshoot

- Press **Ctrl+C** in the tunnel window to stop public sharing. The next run
  gets a new URL. To change the allowlist, stop and relaunch the tunnel.
- If the site does not load, first check `http://localhost:8080/launch.html`
  on this PC. Run `share-with-groupmates.ps1 -CheckOnly` to check the local
  server and signed tunnel executable without making it public.
- If the email code does not arrive, verify the exact allowed email, then
  check spam. An address not entered at launch cannot use the link.
- `Failed to fetch features ... cfd-features.argotunnel.com` is a DNS TXT
  lookup warning, not proof that the tunnel failed. If the window later says
  `Registered tunnel connection` and the HTTPS link opens, keep using it.
  On this PC the router DNS (`192.168.1.1`) times out on that TXT lookup,
  while public DNS (`1.1.1.1` and `8.8.8.8`) answers it. To remove the warning,
  an administrator can change the active Windows network adapter's preferred
  DNS to `1.1.1.1` and alternate DNS to `1.0.0.1`, then run
  `ipconfig /flushdns` and restart the tunnel. This changes DNS for the whole
  PC, so do it only if allowed on your network. Do not disable tunnel
  pre-checks or turn off its email gate merely to hide this warning.
- The local PHP server, MariaDB, tunnel, PC, and internet connection must all
  stay on. The address and availability are not guaranteed. This is for
  temporary testing, not a reliable production deployment.
- Do **not** change the ESP32 API URL to this protected tunnel: the device
  cannot complete the browser email challenge. Keep the terminal on its
  existing local-network API configuration.
