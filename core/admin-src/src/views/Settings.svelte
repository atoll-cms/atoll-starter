<script>
  import { onMount } from 'svelte';
  import { settings, themes, addToast, confirm } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let siteName = $state('');
  let baseUrl = $state('');
  let updaterChannel = $state('stable');
  let updaterManifestUrl = $state('');
  let selectedTheme = $state('default');
  let appearanceVarsText = $state('');
  let smtpDriver = $state('mail');
  let smtpHost = $state('localhost');
  let smtpPort = $state('587');
  let smtpUsername = $state('');
  let smtpPassword = $state('');
  let smtpEncryption = $state('tls');
  let smtpSendmailPath = $state('');
  let smtpFromEmail = $state('noreply@example.com');
  let smtpFromName = $state('atoll-cms');
  let smtpPostmarkToken = $state('');
  let smtpPostmarkEndpoint = $state('https://api.postmarkapp.com/email');
  let smtpMailgunDomain = $state('');
  let smtpMailgunApiKey = $state('');
  let smtpMailgunEndpoint = $state('');
  let smtpSesRegion = $state('eu-central-1');
  let smtpSesAccessKey = $state('');
  let smtpSesSecretKey = $state('');
  let smtpSesSessionToken = $state('');
  let smtpSesEndpoint = $state('');
  let backupS3Enabled = $state(false);
  let backupS3Endpoint = $state('');
  let backupS3Region = $state('eu-central-1');
  let backupS3Bucket = $state('');
  let backupS3AccessKey = $state('');
  let backupS3SecretKey = $state('');
  let backupS3Prefix = $state('atoll-backups');
  let backupS3PathStyle = $state(true);
  let backupScheduleEnabled = $state(false);
  let backupScheduleFrequency = $state('daily');
  let backupScheduleTime = $state('03:00');
  let backupScheduleWeekday = $state('1');

  let backupSftpEnabled = $state(false);
  let backupSftpHost = $state('');
  let backupSftpPort = $state('22');
  let backupSftpUser = $state('');
  let backupSftpPassword = $state('');
  let backupSftpPrivateKeyFile = $state('');
  let backupSftpPublicKeyFile = $state('');
  let backupSftpPassphrase = $state('');
  let backupSftpPath = $state('/backups/atoll');
  let saving = $state(false);
  let savingUsers = $state(false);

  let users = $state([]);
  let usersLoaded = $state(false);
  let newUserUsername = $state('');
  let newUserPassword = $state('');
  let newUserRole = $state('editor');
  let newUserEnabled = $state(true);

  onMount(async () => {
    await loadUsers();
  });

  $effect(() => {
    siteName = $settings?.name || '';
    baseUrl = $settings?.base_url || '';
    updaterChannel = $settings?.updater?.channel || 'stable';
    updaterManifestUrl = $settings?.updater?.manifest_url || '';
    selectedTheme = $settings?.appearance?.theme || 'default';
    appearanceVarsText = formatCssVariables($settings?.appearance?.custom_variables || {});
    smtpDriver = $settings?.smtp?.driver || 'mail';
    smtpHost = $settings?.smtp?.host || 'localhost';
    smtpPort = String($settings?.smtp?.port || '587');
    smtpUsername = $settings?.smtp?.username || '';
    smtpPassword = $settings?.smtp?.password || '';
    smtpEncryption = $settings?.smtp?.encryption || 'tls';
    smtpSendmailPath = $settings?.smtp?.sendmail_path || '';
    smtpFromEmail = $settings?.smtp?.from_email || 'noreply@example.com';
    smtpFromName = $settings?.smtp?.from_name || 'atoll-cms';
    smtpPostmarkToken = $settings?.smtp?.api?.postmark?.token || '';
    smtpPostmarkEndpoint = $settings?.smtp?.api?.postmark?.endpoint || 'https://api.postmarkapp.com/email';
    smtpMailgunDomain = $settings?.smtp?.api?.mailgun?.domain || '';
    smtpMailgunApiKey = $settings?.smtp?.api?.mailgun?.api_key || '';
    smtpMailgunEndpoint = $settings?.smtp?.api?.mailgun?.endpoint || '';
    smtpSesRegion = $settings?.smtp?.api?.ses?.region || 'eu-central-1';
    smtpSesAccessKey = $settings?.smtp?.api?.ses?.access_key || '';
    smtpSesSecretKey = $settings?.smtp?.api?.ses?.secret_key || '';
    smtpSesSessionToken = $settings?.smtp?.api?.ses?.session_token || '';
    smtpSesEndpoint = $settings?.smtp?.api?.ses?.endpoint || '';
    backupS3Enabled = !!$settings?.backup?.targets?.s3?.enabled;
    backupS3Endpoint = $settings?.backup?.targets?.s3?.endpoint || '';
    backupS3Region = $settings?.backup?.targets?.s3?.region || 'eu-central-1';
    backupS3Bucket = $settings?.backup?.targets?.s3?.bucket || '';
    backupS3AccessKey = $settings?.backup?.targets?.s3?.access_key || '';
    backupS3SecretKey = $settings?.backup?.targets?.s3?.secret_key || '';
    backupS3Prefix = $settings?.backup?.targets?.s3?.prefix || 'atoll-backups';
    backupS3PathStyle = $settings?.backup?.targets?.s3?.path_style ?? true;
    backupScheduleEnabled = !!$settings?.backup?.schedule?.enabled;
    backupScheduleFrequency = $settings?.backup?.schedule?.frequency || 'daily';
    backupScheduleTime = $settings?.backup?.schedule?.time || '03:00';
    backupScheduleWeekday = String($settings?.backup?.schedule?.weekday || '1');

    backupSftpEnabled = !!$settings?.backup?.targets?.sftp?.enabled;
    backupSftpHost = $settings?.backup?.targets?.sftp?.host || '';
    backupSftpPort = String($settings?.backup?.targets?.sftp?.port || '22');
    backupSftpUser = $settings?.backup?.targets?.sftp?.username || '';
    backupSftpPassword = $settings?.backup?.targets?.sftp?.password || '';
    backupSftpPrivateKeyFile = $settings?.backup?.targets?.sftp?.private_key_file || '';
    backupSftpPublicKeyFile = $settings?.backup?.targets?.sftp?.public_key_file || '';
    backupSftpPassphrase = $settings?.backup?.targets?.sftp?.passphrase || '';
    backupSftpPath = $settings?.backup?.targets?.sftp?.path || '/backups/atoll';
  });

  async function saveSettings(event) {
    event.preventDefault();
    saving = true;

    try {
      await api('/admin/api/settings/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          settings: {
            name: siteName,
            base_url: baseUrl,
            updater: {
              ...($settings.updater || {}),
              channel: updaterChannel,
              manifest_url: updaterManifestUrl
            },
            appearance: {
              ...($settings.appearance || {}),
              theme: selectedTheme,
              custom_variables: parseCssVariables(appearanceVarsText)
            },
            smtp: {
              ...($settings.smtp || {}),
              driver: smtpDriver,
              host: smtpHost,
              port: Number(smtpPort || 587),
              username: smtpUsername,
              password: smtpPassword,
              encryption: smtpEncryption,
              sendmail_path: smtpSendmailPath,
              from_email: smtpFromEmail,
              from_name: smtpFromName,
              api: {
                ...($settings.smtp?.api || {}),
                postmark: {
                  ...($settings.smtp?.api?.postmark || {}),
                  token: smtpPostmarkToken,
                  endpoint: smtpPostmarkEndpoint
                },
                mailgun: {
                  ...($settings.smtp?.api?.mailgun || {}),
                  domain: smtpMailgunDomain,
                  api_key: smtpMailgunApiKey,
                  endpoint: smtpMailgunEndpoint
                },
                ses: {
                  ...($settings.smtp?.api?.ses || {}),
                  region: smtpSesRegion,
                  access_key: smtpSesAccessKey,
                  secret_key: smtpSesSecretKey,
                  session_token: smtpSesSessionToken,
                  endpoint: smtpSesEndpoint
                }
              }
            },
            backup: {
              ...($settings.backup || {}),
              schedule: {
                ...($settings.backup?.schedule || {}),
                enabled: backupScheduleEnabled,
                frequency: backupScheduleFrequency,
                time: backupScheduleTime,
                weekday: Number(backupScheduleWeekday || 1)
              },
              targets: {
                ...($settings.backup?.targets || {}),
                local: {
                  ...($settings.backup?.targets?.local || {}),
                  enabled: true
                },
                s3: {
                  ...($settings.backup?.targets?.s3 || {}),
                  enabled: backupS3Enabled,
                  endpoint: backupS3Endpoint,
                  region: backupS3Region,
                  bucket: backupS3Bucket,
                  access_key: backupS3AccessKey,
                  secret_key: backupS3SecretKey,
                  prefix: backupS3Prefix,
                  path_style: backupS3PathStyle
                },
                sftp: {
                  ...($settings.backup?.targets?.sftp || {}),
                  enabled: backupSftpEnabled,
                  host: backupSftpHost,
                  port: Number(backupSftpPort || 22),
                  username: backupSftpUser,
                  password: backupSftpPassword,
                  private_key_file: backupSftpPrivateKeyFile,
                  public_key_file: backupSftpPublicKeyFile,
                  passphrase: backupSftpPassphrase,
                  path: backupSftpPath
                }
              }
            },
            security: $settings.security || {}
          }
        })
      });

      const t = await api('/admin/api/themes');
      themes.set(t.themes);
      addToast('Settings gespeichert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function createBackup() {
    try {
      const result = await api('/admin/api/backup/create', { method: 'POST' });
      if (result.ok) {
        if (result.partial) {
          const errorText = Array.isArray(result.errors) && result.errors.length > 0
            ? ` (${result.errors.join('; ')})`
            : '';
          addToast(`Backup lokal erstellt, Remote unvollstaendig${errorText}`, 'info', 7000);
        } else {
          addToast(`Backup erstellt: ${result.file}`, 'success');
        }
      } else {
        addToast(result.error || 'Backup fehlgeschlagen.', 'error');
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function clearCache() {
    const confirmed = await confirm('Cache leeren', 'Soll der komplette Cache geleert werden?');
    if (!confirmed) return;

    try {
      await api('/admin/api/cache/clear', { method: 'POST' });
      addToast('Cache geleert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function loadUsers() {
    try {
      const result = await api('/admin/api/users');
      users = Array.isArray(result.users) ? result.users : [];
      usersLoaded = true;
    } catch (err) {
      usersLoaded = true;
      addToast(err.message, 'error');
    }
  }

  async function saveUsers() {
    savingUsers = true;
    try {
      await api('/admin/api/users/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          users: users.map((u) => ({
            username: u.username,
            role: u.role,
            enabled: !!u.enabled
          }))
        })
      });
      await loadUsers();
      addToast('Benutzer gespeichert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingUsers = false;
    }
  }

  async function createUser(event) {
    event.preventDefault();
    if (!newUserUsername.trim() || !newUserPassword) {
      addToast('Username und Passwort sind erforderlich.', 'error');
      return;
    }

    savingUsers = true;
    try {
      await api('/admin/api/users/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          users: users.map((u) => ({
            username: u.username,
            role: u.role,
            enabled: !!u.enabled
          })),
          create: {
            username: newUserUsername.trim(),
            password: newUserPassword,
            role: newUserRole,
            enabled: !!newUserEnabled
          }
        })
      });
      newUserUsername = '';
      newUserPassword = '';
      newUserRole = 'editor';
      newUserEnabled = true;
      await loadUsers();
      addToast('Benutzer angelegt.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingUsers = false;
    }
  }

  function parseCssVariables(source) {
    const rows = {};
    const lines = String(source || '').split('\n');
    for (const rawLine of lines) {
      const line = rawLine.trim();
      if (!line || line.startsWith('#')) continue;
      const cleaned = line.endsWith(';') ? line.slice(0, -1).trim() : line;
      const sep = cleaned.indexOf(':');
      if (sep <= 2) continue;
      const key = cleaned.slice(0, sep).trim();
      const value = cleaned.slice(sep + 1).trim();
      if (!/^--[a-zA-Z0-9_-]+$/.test(key) || !value) continue;
      rows[key] = value;
    }
    return rows;
  }

  function formatCssVariables(variables) {
    if (!variables || typeof variables !== 'object') return '';
    return Object.entries(variables)
      .filter(([key, value]) => /^--[a-zA-Z0-9_-]+$/.test(String(key)) && String(value || '').trim() !== '')
      .map(([key, value]) => `${key}: ${String(value).trim()};`)
      .join('\n');
  }
</script>

<div class="settings-view">
  <div class="page-header">
    <h1>Settings</h1>
  </div>

  <form class="section-card" onsubmit={saveSettings}>
    <div class="section-header">
      <h3>Allgemein</h3>
    </div>
    <div class="form-body">
      <div class="field">
        <label for="site-name">Site Name</label>
        <input id="site-name" bind:value={siteName}>
      </div>
      <div class="field">
        <label for="base-url">Base URL</label>
        <input id="base-url" bind:value={baseUrl} placeholder="https://example.com">
      </div>
      <div class="field-row">
        <div class="field">
          <label for="updater-channel">Update Channel</label>
          <input id="updater-channel" bind:value={updaterChannel}>
        </div>
        <div class="field">
          <label for="updater-url">Manifest URL</label>
          <input id="updater-url" bind:value={updaterManifestUrl} placeholder="https://...">
        </div>
      </div>
      <div class="field">
        <label for="theme-select">Theme</label>
        <select id="theme-select" bind:value={selectedTheme}>
          {#each $themes as t}
            <option value={t.id}>{t.id}</option>
          {/each}
        </select>
      </div>

      <div class="field">
        <label for="appearance-vars">Appearance CSS Variablen</label>
        <textarea
          id="appearance-vars"
          rows="6"
          bind:value={appearanceVarsText}
          placeholder="--brand: #0ea5e9;&#10;--radius-lg: 16px;">
        </textarea>
        <p class="field-note">Eine Zeile pro Variable im Format <code>--name: wert;</code>. Wird als <code>:root</code>-Override injiziert.</p>
      </div>

      <div class="fieldset">
        <h4>Mail Versand</h4>
        <p class="field-note">Treiber: `mail`, `smtp`, `sendmail`, `postmark`, `mailgun`, `ses`.</p>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="smtp-driver">Treiber</label>
          <select id="smtp-driver" bind:value={smtpDriver}>
            <option value="mail">mail (PHP)</option>
            <option value="smtp">smtp</option>
            <option value="sendmail">sendmail</option>
            <option value="postmark">postmark (API)</option>
            <option value="mailgun">mailgun (API)</option>
            <option value="ses">ses (API)</option>
          </select>
        </div>
        <div class="field">
          <label for="smtp-from-email">From E-Mail</label>
          <input id="smtp-from-email" bind:value={smtpFromEmail} placeholder="noreply@example.com">
        </div>
      </div>

      <div class="field">
        <label for="smtp-from-name">From Name</label>
        <input id="smtp-from-name" bind:value={smtpFromName} placeholder="atoll-cms">
      </div>

      {#if smtpDriver === 'smtp'}
        <div class="field-row">
          <div class="field">
            <label for="smtp-host">SMTP Host</label>
            <input id="smtp-host" bind:value={smtpHost} placeholder="smtp.example.com">
          </div>
          <div class="field">
            <label for="smtp-port">SMTP Port</label>
            <input id="smtp-port" bind:value={smtpPort} placeholder="587">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="smtp-user">SMTP Benutzer</label>
            <input id="smtp-user" bind:value={smtpUsername}>
          </div>
          <div class="field">
            <label for="smtp-pass">SMTP Passwort</label>
            <input id="smtp-pass" bind:value={smtpPassword} type="password">
          </div>
        </div>
        <div class="field">
          <label for="smtp-encryption">SMTP Verschluesselung</label>
          <input id="smtp-encryption" bind:value={smtpEncryption} placeholder="tls / ssl / ''">
        </div>
      {/if}

      {#if smtpDriver === 'sendmail'}
        <div class="field">
          <label for="smtp-sendmail-path">Sendmail Pfad</label>
          <input id="smtp-sendmail-path" bind:value={smtpSendmailPath} placeholder="/usr/sbin/sendmail -bs">
        </div>
      {/if}

      {#if smtpDriver === 'postmark'}
        <div class="field-row">
          <div class="field">
            <label for="smtp-postmark-token">Postmark Token</label>
            <input id="smtp-postmark-token" bind:value={smtpPostmarkToken} type="password" placeholder="env:POSTMARK_TOKEN">
          </div>
          <div class="field">
            <label for="smtp-postmark-endpoint">Postmark Endpoint</label>
            <input id="smtp-postmark-endpoint" bind:value={smtpPostmarkEndpoint} placeholder="https://api.postmarkapp.com/email">
          </div>
        </div>
      {/if}

      {#if smtpDriver === 'mailgun'}
        <div class="field-row">
          <div class="field">
            <label for="smtp-mailgun-domain">Mailgun Domain</label>
            <input id="smtp-mailgun-domain" bind:value={smtpMailgunDomain} placeholder="mg.example.com">
          </div>
          <div class="field">
            <label for="smtp-mailgun-key">Mailgun API Key</label>
            <input id="smtp-mailgun-key" bind:value={smtpMailgunApiKey} type="password" placeholder="env:MAILGUN_API_KEY">
          </div>
        </div>
        <div class="field">
          <label for="smtp-mailgun-endpoint">Mailgun Endpoint (optional)</label>
          <input id="smtp-mailgun-endpoint" bind:value={smtpMailgunEndpoint} placeholder="https://api.mailgun.net/v3/<domain>/messages">
        </div>
      {/if}

      {#if smtpDriver === 'ses'}
        <div class="field-row">
          <div class="field">
            <label for="smtp-ses-region">SES Region</label>
            <input id="smtp-ses-region" bind:value={smtpSesRegion} placeholder="eu-central-1">
          </div>
          <div class="field">
            <label for="smtp-ses-access">SES Access Key</label>
            <input id="smtp-ses-access" bind:value={smtpSesAccessKey}>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="smtp-ses-secret">SES Secret Key</label>
            <input id="smtp-ses-secret" bind:value={smtpSesSecretKey} type="password" placeholder="env:AWS_SECRET_ACCESS_KEY">
          </div>
          <div class="field">
            <label for="smtp-ses-token">SES Session Token (optional)</label>
            <input id="smtp-ses-token" bind:value={smtpSesSessionToken} type="password" placeholder="env:AWS_SESSION_TOKEN">
          </div>
        </div>
        <div class="field">
          <label for="smtp-ses-endpoint">SES Endpoint (optional)</label>
          <input id="smtp-ses-endpoint" bind:value={smtpSesEndpoint} placeholder="https://email.eu-central-1.amazonaws.com/v2/email/outbound-emails">
        </div>
      {/if}

      <div class="fieldset">
        <h4>Backup Ziele</h4>
        <p class="field-note">Lokales ZIP wird immer erzeugt. Optionaler Upload zu S3/SFTP.</p>
      </div>

      <div class="field field--check">
        <label><input type="checkbox" bind:checked={backupScheduleEnabled}> Geplante Backups aktivieren</label>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-schedule-frequency">Rhythmus</label>
          <select id="backup-schedule-frequency" bind:value={backupScheduleFrequency}>
            <option value="daily">Taeglich</option>
            <option value="weekly">Woechentlich</option>
          </select>
        </div>
        <div class="field">
          <label for="backup-schedule-time">Uhrzeit</label>
          <input id="backup-schedule-time" type="time" bind:value={backupScheduleTime}>
        </div>
      </div>
      {#if backupScheduleFrequency === 'weekly'}
        <div class="field">
          <label for="backup-schedule-weekday">Wochentag</label>
          <select id="backup-schedule-weekday" bind:value={backupScheduleWeekday}>
            <option value="1">Montag</option>
            <option value="2">Dienstag</option>
            <option value="3">Mittwoch</option>
            <option value="4">Donnerstag</option>
            <option value="5">Freitag</option>
            <option value="6">Samstag</option>
            <option value="7">Sonntag</option>
          </select>
        </div>
      {/if}
      <p class="field-note">Cron-Entrypoint: <code>php bin/atoll backup:run</code></p>

      <div class="field field--check">
        <label><input type="checkbox" bind:checked={backupS3Enabled}> S3 Upload aktivieren</label>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-s3-endpoint">S3 Endpoint</label>
          <input id="backup-s3-endpoint" bind:value={backupS3Endpoint} placeholder="https://s3.eu-central-1.amazonaws.com">
        </div>
        <div class="field">
          <label for="backup-s3-region">S3 Region</label>
          <input id="backup-s3-region" bind:value={backupS3Region} placeholder="eu-central-1">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-s3-bucket">S3 Bucket</label>
          <input id="backup-s3-bucket" bind:value={backupS3Bucket}>
        </div>
        <div class="field">
          <label for="backup-s3-prefix">S3 Prefix</label>
          <input id="backup-s3-prefix" bind:value={backupS3Prefix} placeholder="atoll-backups">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-s3-access">S3 Access Key</label>
          <input id="backup-s3-access" bind:value={backupS3AccessKey}>
        </div>
        <div class="field">
          <label for="backup-s3-secret">S3 Secret Key</label>
          <input id="backup-s3-secret" bind:value={backupS3SecretKey} type="password">
        </div>
      </div>
      <div class="field field--check">
        <label><input type="checkbox" bind:checked={backupS3PathStyle}> S3 Path-Style URL</label>
      </div>

      <div class="field field--check">
        <label><input type="checkbox" bind:checked={backupSftpEnabled}> SFTP Upload aktivieren</label>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-sftp-host">SFTP Host</label>
          <input id="backup-sftp-host" bind:value={backupSftpHost}>
        </div>
        <div class="field">
          <label for="backup-sftp-port">SFTP Port</label>
          <input id="backup-sftp-port" bind:value={backupSftpPort}>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-sftp-user">SFTP Benutzer</label>
          <input id="backup-sftp-user" bind:value={backupSftpUser}>
        </div>
        <div class="field">
          <label for="backup-sftp-pass">SFTP Passwort</label>
          <input id="backup-sftp-pass" bind:value={backupSftpPassword} type="password">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-sftp-private">Private Key Datei</label>
          <input id="backup-sftp-private" bind:value={backupSftpPrivateKeyFile} placeholder="/pfad/id_rsa">
        </div>
        <div class="field">
          <label for="backup-sftp-public">Public Key Datei</label>
          <input id="backup-sftp-public" bind:value={backupSftpPublicKeyFile} placeholder="/pfad/id_rsa.pub">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="backup-sftp-passphrase">Key Passphrase</label>
          <input id="backup-sftp-passphrase" bind:value={backupSftpPassphrase} type="password">
        </div>
        <div class="field">
          <label for="backup-sftp-path">Remote Pfad</label>
          <input id="backup-sftp-path" bind:value={backupSftpPath} placeholder="/backups/atoll">
        </div>
      </div>

      <button type="submit" class="save-btn" disabled={saving}>
        {saving ? 'Speichert...' : 'Speichern'}
      </button>
    </div>
  </form>

  <div class="section-card">
    <div class="section-header">
      <h3>Benutzer & Rollen</h3>
    </div>
    <div class="form-body">
      {#if usersLoaded}
        <div class="users-table-wrap">
          <table class="users-table">
            <thead>
              <tr>
                <th>Username</th>
                <th>Rolle</th>
                <th>Aktiv</th>
                <th>2FA</th>
              </tr>
            </thead>
            <tbody>
              {#each users as row, idx}
                <tr>
                  <td><code>{row.username}</code></td>
                  <td>
                    <select
                      value={row.role}
                      oninput={(event) => {
                        const value = event.currentTarget?.value || 'editor';
                        users = users.map((item, i) => i === idx ? { ...item, role: value } : item);
                      }}>
                      <option value="owner">owner</option>
                      <option value="editor">editor</option>
                      <option value="support">support</option>
                    </select>
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      checked={!!row.enabled}
                      oninput={(event) => {
                        const value = !!event.currentTarget?.checked;
                        users = users.map((item, i) => i === idx ? { ...item, enabled: value } : item);
                      }}>
                  </td>
                  <td>{row.twofa_enabled ? 'Ja' : 'Nein'}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>

        <div class="users-actions">
          <button class="save-btn" type="button" onclick={saveUsers} disabled={savingUsers}>
            {savingUsers ? 'Speichert...' : 'Benutzer speichern'}
          </button>
        </div>

        <form class="create-user-form" onsubmit={createUser}>
          <h4>Neuen Benutzer anlegen</h4>
          <div class="field-row">
            <div class="field">
              <label for="new-user-username">Username</label>
              <input id="new-user-username" bind:value={newUserUsername} placeholder="editor-team">
            </div>
            <div class="field">
              <label for="new-user-password">Passwort</label>
              <input id="new-user-password" bind:value={newUserPassword} type="password" placeholder="Sicheres Passwort">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="new-user-role">Rolle</label>
              <select id="new-user-role" bind:value={newUserRole}>
                <option value="owner">owner</option>
                <option value="editor">editor</option>
                <option value="support">support</option>
              </select>
            </div>
            <div class="field field--check field--new-user-enabled">
              <label><input type="checkbox" bind:checked={newUserEnabled}> Aktiv</label>
            </div>
          </div>
          <button type="submit" class="save-btn" disabled={savingUsers}>
            {savingUsers ? 'Anlegen...' : 'Benutzer anlegen'}
          </button>
        </form>
      {:else}
        <p class="field-note">Benutzer werden geladen...</p>
      {/if}
    </div>
  </div>

  <div class="actions-grid">
    <button class="action-card" onclick={createBackup}>
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      <span>Backup erstellen</span>
    </button>
    <button class="action-card" onclick={clearCache}>
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      <span>Cache leeren</span>
    </button>
  </div>
</div>

<style>
  .settings-view { max-width: 700px; }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 1.5rem;
    letter-spacing: -0.02em;
  }

  .section-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 1rem;
  }

  .section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--line);
  }

  .section-header h3 { margin: 0; font-size: 0.95rem; font-weight: 600; }

  .form-body { padding: 1.25rem; }

  .field {
    margin-bottom: 1rem;
  }

  .field label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 0.35rem;
  }

  .field input,
  .field select,
  .field textarea {
    width: 100%;
    padding: 0.6rem 0.75rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
    transition: border-color 0.15s;
  }

  .field input:focus,
  .field select:focus,
  .field textarea:focus {
    outline: none;
    border-color: var(--brand);
  }

  .field textarea {
    resize: vertical;
    min-height: 6.5rem;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 0.8rem;
    line-height: 1.5;
  }

  .field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
  }

  .fieldset {
    margin: 1rem 0 0.25rem;
    padding-top: 0.75rem;
    border-top: 1px dashed var(--line);
  }

  .fieldset h4 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
  }

  .field-note {
    margin: 0.25rem 0 0;
    color: var(--muted);
    font-size: 0.8rem;
  }

  .field--check label {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.85rem;
    color: var(--text);
    font-weight: 500;
  }

  .field--check input[type="checkbox"] {
    width: auto;
  }

  .save-btn {
    padding: 0.6rem 1.5rem;
    background: var(--brand);
    border: none;
    border-radius: 8px;
    color: #1a1a1a;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  .save-btn:hover:not(:disabled) { opacity: 0.9; }
  .save-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .users-table-wrap {
    overflow-x: auto;
    margin-bottom: 0.8rem;
  }

  .users-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 520px;
  }

  .users-table th,
  .users-table td {
    text-align: left;
    padding: 0.55rem 0.45rem;
    border-bottom: 1px solid var(--line);
    font-size: 0.85rem;
  }

  .users-table th {
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 600;
  }

  .users-actions {
    margin: 0.6rem 0 1rem;
  }

  .create-user-form {
    border-top: 1px dashed var(--line);
    padding-top: 0.9rem;
  }

  .create-user-form h4 {
    margin: 0 0 0.75rem;
    font-size: 0.9rem;
    font-weight: 600;
  }

  .field--new-user-enabled {
    display: flex;
    align-items: end;
  }

  .actions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
  }

  .action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.5rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    color: var(--muted);
    font: inherit;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
  }

  .action-card:hover {
    border-color: var(--brand);
    color: var(--text);
    transform: translateY(-1px);
  }

  @media (max-width: 600px) {
    .field-row { grid-template-columns: 1fr; }
    .actions-grid { grid-template-columns: 1fr; }
  }
</style>
