# Using the admin

This part of the manual is for the people who work in the admin: what
each screen does and how to find your way. What you see depends on
your account. A resource, a field or a button you may not use is not
shown at all.

## Signing in

How you sign in depends on how your organization deployed the admin:

- **Standalone** — the admin's own sign-in page asks for your e-mail
  address and password. If you opened a link while signed out, you land
  on that page once signed in.
- **Hosted** — your application's own sign-in page. After signing in you
  are taken to the admin.

An account that the admin does not admit is told so on the sign-in
page. When your session expires, the next page you open asks you to
sign in again.

## The shell

- **Navigation drawer** — the dashboard, then every resource you may
  list, grouped (for example *Catalog*, *Sales*), then custom pages,
  **Jobs** and **Settings**. A coloured count beside a resource is a
  *badge*: how many of its records need attention, such as products low
  on stock. Badges refresh every minute and after each navigation.
- **App bar** — **Search**, the notifications inbox, the light/dark
  theme toggle, and your account menu with **Sign out**.
- **Toggle navigation** — the menu button collapses the drawer, which
  it does on its own on narrow screens.

## The dashboard

The home page shows the *main* dashboard, when your application has
one: cards of figures, trends, breakdowns and short tables. A card with
several ranges (7 days, 30 days, 90 days, a year) has a range selector;
a table card may link to the full list with **See all**.

## Search

Press <kbd>Ctrl</kbd>+<kbd>K</kbd> (<kbd>⌘</kbd>+<kbd>K</kbd> on a Mac),
or **Search** in the app bar, and type. Matching records appear under
*Records* — with a subtitle such as a SKU, and a thumbnail when the
record has an image — followed by the resources and custom pages whose
names match. Choose one to open it.

## Notifications

The bell in the app bar is your inbox. Background jobs and actions leave
a notification there when they finish — an export ready to download, an
import with its failed rows. Open one to go where it points, or
**Mark all read**.

## Jobs

**Jobs** lists your background work: large exports, imports and actions
that run in the background. Each shows its kind, resource, status
(*queued*, *running*, *done*, *failed*), progress and start time. Open a
job to follow its progress; a finished export or an import with failed
rows offers **Download**.

## Settings

**Settings** holds the groups of values your account may manage, such
as *Branding*: the admin's title, logo, colours, default theme and
footer line. Save a group and it applies at once.

## Acting as another user

An administrator allowed to impersonate sees **Impersonate** on a
user's page. While you act as someone else, a banner says so, and
everything you do is recorded as theirs with you as the impersonator.
**Stop impersonating** returns you to your own account. When the
impersonation runs out, the admin returns you to your own account and
says so, or asks you to sign in again when your own session has ended
too.

## See also

- {doc}`lists` — finding and changing records in bulk.
- {doc}`records` — viewing, editing and deleting one record.
