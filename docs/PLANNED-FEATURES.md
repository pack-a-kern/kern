# Planned features

Work agreed on but not designed in full yet. Each is designed together, explained and agreed before
anything is built. A plan, not a record: when one is built, its entry moves to a handoff note.

---

## 1. Sharing a taxpayer

Agreed 2026-10-20, while fixing "per-tenant roles are not enforced" (`SECURITY-FINDINGS.md`). Today
only an OWNER membership opens a taxpayer (`MembershipRole::opening()`); every other role is refused at
the tenant gate until this feature gives it rules.

**The idea so far:**

- The owner invites someone **by email**.
- Each person uses **their own account** — nobody shares a password or signs in as the owner.
- The owner can **revoke** an invitation or a person's access **at any time**, and it ends at once.

**Still to decide:**

- What each role may do: ADMIN, ACCOUNTANT and VIEWER exist in `MembershipRole` with no rules yet —
  read only, edit transactions, file declarations, connect SPV, see documents, invite others.
- What happens to an invitation sent to an address with no account (and how it says so without
  revealing whether the address has one — see the registration fix in `SECURITY-FINDINGS.md`).
- Whether the owner can see what a shared person did (the taxpayer's own activity, which today only a
  super administrator can read).
- How long an invitation stays valid.

**Constraints already set:** the rule lives in one place (`MembershipRole` / `SubjectMembership`), the
tenant gate stays the only door, and every grant and revoke is written to the audit chain.

---

## 2. Backups

Agreed 2026-10-20 as the answer to "no backups exist" (`SECURITY-FINDINGS.md`). Today nothing on the
server makes one, and losing the disk loses every account, taxpayer, transaction and declaration.

**What has to be saved:**

| What | Where now | If it is lost |
|---|---|---|
| The database | `postgres_data` volume | all data |
| The master key (OpenBao) | `openbao_data` volume | the database becomes unreadable for good, even from a database backup |
| The secrets file | `apps/api/.env` | the application cannot start, and some data cannot be read |
| Uploaded pictures and the like | `app_storage` volume | small losses |

Documents are on the external MinIO, not on this server; that MinIO needs its own backup.

**The trap:** `openbao_data` also holds `init.json` — the unseal key and the root token. A database
backup and a key backup kept together are everything, readable. The two are never stored side by side,
and this is tied to the unseal-key decision still open.

**The plan so far:**

1. `make backup` writes two separate files: data (database and uploads) and keys (OpenBao and `.env`).
2. Both encrypted to a **public key**: the server can make a backup and cannot open one, and the key
   that opens them stays with the owner, off the server — a broken-into server cannot read old backups.
3. Copied **off the server**: a backup on the same disk dies with it.
4. Daily and automatic, keeping the last 14 (configurable, not written in code).
5. `make restore`, and a test that really restores a backup into a throwaway copy and checks the
   application works. A backup nobody has restored is not a backup.
6. The steps in `DEPLOYMENT.md`, in plain words.

**Still to decide:**

- Where backups go: another server, an S3 or MinIO bucket, a disk elsewhere.
- How often, and how many to keep.
- Where the keys backup lives, apart from the data backup.
- The unseal key itself (`init.json`): kept as it is, split, or moved off the server.
