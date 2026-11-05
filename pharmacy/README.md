# HireSync

HireSync is a CRM for a NSW pharmacy recruitment agency, built as an extension for [EspoCRM](https://www.espocrm.com) 10. It holds candidates, credentials, clients, job orders, submissions, permanent placements and locum shifts, and it refuses to confirm a shift unless the candidate's credentials are verified and current to the end of the shift, or to start a placement unless they are verified and current on its first day.

Before you put real candidates in it, get advice on the [legal questions](#questions-for-your-adviser) the software can't settle, and read the [limits](#limits).

## What it enforces

| Rule | What happens |
| --- | --- |
| Shifts | Confirming a shift needs every credential the client and job require, and every one the roles on the shift, the job and the candidate's own record require, each verified and current through the last day of the shift. Otherwise the save is refused. |
| Placements | Starting a placement needs the same, current on its start date. The end date isn't checked, because a fixed term can outlast a year of registration or insurance. The expiry reminders cover the rest of the term. |
| Refusals | The message names the role credentials that are missing. A type only a client or job requires shows as `a client or job requirement`, because whether one person holds it can be health information. Recruiters can still read what a client or job requires, so where there is one such type they can infer which, and trying different jobs and dates can narrow down several. The API refuses in the same way, and a mass update skips the record, so check how many it reports as updated. |
| Moving work | A shift, placement, credential or submission moves only by editing the record, so these checks run. Panels on a candidate, client or job offer Create, not Select or Unlink. Work must be for its job's client. |
| Candidate roles | A recruiter sets a candidate's role when creating them. After that only a manager or administrator can change it, because that role counts on all their work. |
| New roles | A role added in Entity Manager can't be confirmed until it has rules, so it can't pass by having nothing to check. |
| Double booking | One person can't hold 2 overlapping confirmed shifts. Shifts that only touch are allowed. |
| Verifying a credential | Needs a verification method and an expiry, unless the type has none. It records who verified and when. |
| Changing a checked detail | Returns the credential to `Pending`. Someone has to choose `Verified` again, which is a second save when renewing. |
| Right to work | Needs a stated basis. A time-limited visa also needs its expiry. |
| Working with Children Check | Can be verified only as `Cleared`, `Paid` and with a date of birth. |
| Professional indemnity insurance | Can be verified only with cover of at least $20 million. |
| Dates worked out for you | Police check 3 years from issue. CPR, paediatric CPR and the immuniser annual review 12 months from issue, so each needs an issue date. No credential can be verified with an issue date still to come. |
| Disclosure | A submission can't go past `Shortlisted` without a consent date. |
| Daily check | Refreshes each candidate's status and flags confirmed shifts in the next 14 days that are no longer clear, with a task for each. Raises tasks 90, 30 and 7 days before an expiry for the credential's assigned user or, failing that, whoever verified it, if they are active and can open it. If neither is, no task is raised until a later check finds someone, and that check raises one task for the nearest threshold. |
| Retention hold | A Working with Children Check record can't be deleted, moved to another person or changed to another type, and its candidate can't be deleted, until 7 years after their last work. Work that has started keeps the hold running if it's later cancelled, moved or deleted, and `Work on record until` on the candidate shows that date. |
| Candidate fees | No field exists for charging a candidate, because Fair Trading Act 1987 (NSW) s 49 bans it. |

The figures live in `Resources/metadata/app/rxCompliance.json`. Each legal fact is traced to its source in [SOURCES.md](SOURCES.md).

## Roles

Installing creates 3 roles. Give each staff member one role and at least one team. A recruiter with no team can't assign records.

| Role | Can do | Can't do |
| --- | --- | --- |
| Recruiter | Work their team's candidates, jobs, submissions, placements and shifts. Read all clients. See each candidate's compliance status and summary. | Open credentials or evidence files, export, or edit the required credentials on a client or job. |
| Compliance officer | Manage and verify credentials. Read all candidates, jobs, submissions, placements, shifts, clients and contacts. | Create or edit shifts, delete credentials, or export. |
| Agency manager | Everything on those records, including export and removing a requirement from a client. | Export credentials, which the CRM refuses for every user including administrators. |

All 3 roles see tasks, calls, meetings, emails and documents by team, not across the agency.

When a client's type is set to `NSW Health hospital`, its required credentials are filled in with the police check, Working with Children Check and vaccination evidence. A manager can then remove one for a site where an exception applies, such as a role with no child contact.

## Install

You need a Linux host with Docker Compose and zip. Run these from the `pharmacy` folder of this repository.

Create your settings, then replace every value in `deploy/.env`:

```bash
cp deploy/env.example deploy/.env
```

Start EspoCRM, MariaDB and the job daemon:

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --wait
```

Build the extension and install it:

```bash
(cd extension && zip -qr ../hiresync.zip manifest.json scripts files)
docker compose -f deploy/compose.yaml --env-file deploy/.env cp hiresync.zip espocrm:/tmp/hiresync.zip
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -u www-data espocrm php command.php extension --file=/tmp/hiresync.zip
```

The CRM listens on loopback only. Put an HTTPS reverse proxy in front of it before anyone reaches it over a network, and choose Australian hosting, because the database holds criminal record and health information.

Then, as an administrator:

1. Upload your logo under **Administration > User Interface > Company Logo**.
2. Create your teams, and create each user with one role and at least one team.
3. Set up outbound email under **Administration > Outbound Emails**, then tick **Notifications upon assignment** under **Administration > Notifications** so staff are emailed their tasks. Without this they see tasks only in the CRM.

The brand colours are in `extension/files/client/custom/modules/pharmacy-recruitment/css/brand.css`.

## Upgrade

To upgrade HireSync, install a newer zip in the same way. Settings and roles you have changed stay as they are. New role permissions in a later version therefore don't reach existing roles, so apply them by hand.

To upgrade EspoCRM, change `ESPO_VERSION`, then run the `up` command again after `docker compose pull`. The extension accepts EspoCRM 10. It has been tested on 10.0.0 and 10.0.11, and through an upgrade from one to the other with data in place.

## Uninstall

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -u www-data espocrm php command.php extension -u --name="HireSync"
```

This removes the code and leaves all data, settings and roles in the database. Afterwards, delete `Check pharmacy credentials` under **Administration > Scheduled Jobs** and reset the dashboard under **Administration > User Interface**, because both still point at what was removed.

## Backups

HireSync sets up no backups. Run these on a schedule, encrypt the output, and keep it in Australia and out of any repository, since it holds everything in the CRM.

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction espocrm' > espocrm.sql
```

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -T espocrm tar czf - data custom client/custom > espocrm-files.tgz
```

To restore, use the same settings file as the backup, because `data` holds the matching database password. This replaces everything in the CRM:

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --wait
docker compose -f deploy/compose.yaml --env-file deploy/.env stop daemon
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" espocrm' < espocrm.sql
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -T espocrm tar xzf - < espocrm-files.tgz
docker compose -f deploy/compose.yaml --env-file deploy/.env exec -u www-data espocrm php command.php rebuild
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --wait
```

## Verifying credentials

HireSync records what a person checked. It doesn't contact any register, so a `Verified` status means only that someone looked.

| Credential | Check at | Record |
| --- | --- | --- |
| Ahpra registration | [Ahpra public register](https://www.ahpra.gov.au/Registration/Employer-Services/Check-your-employees-registration.aspx), or Practitioner Information Exchange if you subscribe | Number, type, the expiry shown, and any conditions under Register conditions |
| Right to work | VEVO for visa holders. Passport or citizenship evidence for citizens, because VEVO can't confirm citizenship | Basis, visa expiry, and work conditions under Register conditions |
| Working with Children Check | OCG online verification, using the employer's login | Status, check type, date of birth, expiry and who verified it |
| Professional indemnity insurance | Insurer document in the person's name, or a certified certificate or letter from the third party | Cover, who arranged it and expiry. Check it covers all aspects of practice and all locations |
| National police check | The certificate | Issue date. NSW Health also wants a statutory declaration about offences since, unless the agency obtained the certificate itself |
| NSW Health vaccination evidence | The worker's evidence under PD2026_004 | Expiry. Influenza vaccination is annual, by 1 June |
| Immuniser certificate, CPR and annual review | The certificates | Issue dates. Paediatric CPR is needed for vaccinating children under 14 |
| MMR and ACOP Credentials | The credentialing body's record | The credential held |

A pharmacist's conditions warn and don't block. If a verified credential has anything under Register conditions, the candidate's summary tells the recruiter to check with compliance, without showing the wording.

Temporary NSW Health vaccination compliance is a verified record with the end of the temporary period as its expiry and the undertaking under Register conditions.

## Limits

- **General registration renewal.** Every general registration shows an expiry of 30 November. HireSync treats a credential as expired after the date on its record, so update these records before 1 December. Ahpra has a late period to 31 December, during which the register can still show a person as registered, so decide how you will treat it.
- **Working with Children Check applications.** OCG allows some child-related work on an application in progress, but NSW Health doesn't, and HireSync can't verify an application. If you take this on outside NSW Health, remove the requirement for that client with a manager's approval and note why.
- **Aged care.** Aged care accepts a police certificate or an NDIS Worker Screening Clearance. A client's requirements are all required, so choose one for each site.
- **Staff edits.** The rules catch mistakes, not a determined insider. A recruiter can still clear the job on a shift, lower the role on a job or shift, or add a client with no requirements. Edits show in the record's audit log, and a new client shows who created it. Changes to a client's requirements stay with managers.
- **Active placements.** Nothing flags a placement when a credential lapses during its term. The reminders and the candidate's status are the signal.
- **Reminders after someone leaves.** Reminders go to the credential's assigned user or its verifier, whichever is active and can open it. If neither is, no reminder is raised until someone who can is assigned, and earlier thresholds aren't repeated, so assign a leaver's credentials to someone else.
- **Past work.** The check reads a credential's latest expiry, not its issue date, so a shift entered after the fact can pass on a credential issued later.
- **Duplicates.** Candidates can't be merged, because a merge would move credentials and shifts past the checks. Edit each record to move it. A moved credential returns to `Pending`.
- **Double booking.** If 2 people confirm the same person at the same moment, both can pass. The window is small and the fix is a row lock on the candidate.
- **Deleted records.** EspoCRM keeps a deleted record for 2 months before purging it, so deleting is not immediate erasure.
- **Retention.** Only Working with Children Check records have a hold. Correcting a mistaken shift or placement doesn't shorten it: once its start has passed, its date stays on the candidate. Set a retention review date on each candidate and work through the `Retention review due` filter. An unplaced candidate's immunisation evidence is the strongest case for early deletion.
- **Not tracked.** Recency of practice, CPD, intern ratios and preceptors, student visa work hours, and the pharmacist in charge.
- **Not included.** Payroll, invoicing, award rates, labour hire licence checks, a candidate portal, SMS and interstate rules.

## Questions for your adviser

The research behind this software found these open, and the CRM doesn't answer them:

1. Does employing or on-hiring a locum breach, or expose the locum under, Health Practitioner Regulation National Law (NSW) s 139D(1)(a)? Settle this before choosing the `Agency employs` or `Agency pays contractor` models.
2. Who must verify a Working with Children Check when you place a locum (Child Protection (Working with Children) Act 2012 (NSW) ss 9 and 9A)?
3. Does your locum model trigger labour hire licensing in Victoria, Queensland, South Australia or the ACT? Arranging accommodation is a particular risk in Victoria. NSW has promised a scheme but has none today.
4. How does payroll tax treat contractor locums, and what declaration do you need under Payroll Tax Act 2007 (NSW) s 40(2)?
5. Are you a small business operator under Privacy Act 1988 s 6D(4)(c), and so covered by the Health Records and Information Privacy Act 2002 (NSW)? Assume the Privacy Act applies until told otherwise.
6. Are job alerts and calls to candidates commercial messages or telemarketing? HireSync records marketing consent but sends nothing by itself.
7. Is passing a cost to a candidate a fee under Fair Trading Act 1987 (NSW) s 49?
8. How long should you keep each class of record beyond the statutory minimums, including for limitation periods?

## Changing and testing it

Run the tests on a machine with Docker, zip and curl. This builds a clean stack under its own Compose project, so it can't touch a deployment on the same host, then installs the extension and runs every test inside the container:

```bash
tests/run.sh
```

Deployment uses the official EspoCRM image, so the only code that runs from this repository is under `pharmacy/`. Everything here sits in that folder, so upstream merges stay clear of it:

```bash
git fetch upstream master
git merge upstream/master
```

## Licence

EspoCRM and this extension are under the [GNU AGPL 3.0](../LICENSE.txt). If people use your running copy over a network, you must offer them its source, including any changes you make, so keep your changes in a repository you can link to. Keep the EspoCRM notice in the footer, which the licence requires.

<sub>This is software, not legal advice. Facts were checked on the dates in SOURCES.md and will change.</sub>
