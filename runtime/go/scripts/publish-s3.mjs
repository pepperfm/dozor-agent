// S3 publisher for Dozor Agent release binaries.
//
// Configuration is driven entirely by environment variables:
//   S3_ENDPOINT, S3_BUCKET, S3_REGION, S3_PREFIX, S3_PUBLIC_URL,
//   S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY
//
// Tests can override any value via setDistributionOverride() before running.

import { createHash } from 'node:crypto'
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { S3Client } from 'bun'
import { pathToFileURL } from 'node:url'

// ---------------------------------------------------------------------------
// Distribution config — loaded from env at module init, overridable by tests
// ---------------------------------------------------------------------------

const _defaults = {
  endpoint: 'https://s3.ru1.storage.beget.cloud',
  bucket: 'd12436381c44-spp',
  region: 'ru1',
  prefix: 'dozor-agent/linux/',
  publicUrl: 'https://s3.streams-panel.ru/dozor-agent/linux/',
}

let _distribution = {
  endpoint:    process.env.S3_ENDPOINT || _defaults.endpoint,
  bucket:      process.env.S3_BUCKET || _defaults.bucket,
  region:      process.env.S3_REGION || _defaults.region,
  prefix:      process.env.S3_PREFIX || _defaults.prefix,
  publicUrl:   process.env.S3_PUBLIC_URL || _defaults.publicUrl,
}

/** Override the distribution object (for tests only). */
export function setDistributionOverride(overrides) {
  Object.assign(_distribution, overrides)
}

/** Reset distribution back to live environment values. */
export function resetDistributionOverride() {
  Object.assign(_distribution, {
    endpoint:    process.env.S3_ENDPOINT || _defaults.endpoint,
    bucket:      process.env.S3_BUCKET || _defaults.bucket,
    region:      process.env.S3_REGION || _defaults.region,
    prefix:      process.env.S3_PREFIX || _defaults.prefix,
    publicUrl:   process.env.S3_PUBLIC_URL || _defaults.publicUrl,
  })
}

/** Read-only frozen snapshot — returns a new frozen object each call. */
export const distribution = Object.freeze({
  get endpoint()    { return _distribution.endpoint },
  get bucket()      { return _distribution.bucket },
  get region()      { return _distribution.region },
  get prefix()      { return _distribution.prefix },
  get publicUrl()   { return _distribution.publicUrl },
})

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

function validateEnv(requiredVars) {
  const missing = requiredVars.filter(v => !process.env[v])
  if (missing.length) {
    throw new Error('Missing required S3 environment variable' + (missing.length > 1 ? 's' : '') + ': ' + missing.join(', '))
  }
}

// ---------------------------------------------------------------------------
// Artifact loading & verification
// ---------------------------------------------------------------------------

const sha256 = bytes => createHash('sha256').update(bytes).digest('hex')
const commitPattern = /^[a-f0-9]{40}$/
const binaries = ['dozor-agent-linux-amd64', 'dozor-agent-linux-arm64']

export function loadCandidate(dist, sha, committedAt = 0) {
  if (!Number.isSafeInteger(committedAt) || committedAt < 0) throw new Error('Invalid source commit timestamp.')
  if (!commitPattern.test(sha ?? '')) throw new Error('A 40-character source commit SHA is required.')
  const checksumBytes = readFileSync(join(dist, 'SHA256SUMS'))
  const checksums = new Map()
  for (const line of checksumBytes.toString('utf8').trim().split(/\r?\n/)) {
    const match = /^([a-f0-9]{64})  (dozor-agent-linux-(?:amd64|arm64))$/.exec(line)
    if (!match || checksums.has(match[2])) throw new Error('Unexpected or duplicate SHA256SUMS entry.')
    checksums.set(match[2], match[1])
  }
  if (checksums.size !== binaries.length) throw new Error('Both Linux architectures are required.')

  const files = binaries.map(name => {
    const path = join(dist, name)
    const bytes = readFileSync(path)
    if (bytes.length < 64) throw new Error('Empty or truncated Go binary.')
    const digest = sha256(bytes)
    if (checksums.get(name) !== digest) throw new Error(`Go artifact SHA256 mismatch: ${name}`)
    return { name: `${sha}/${name}`, path, bytes, digest, immutable: true }
  })
  files.push({ name: `${sha}/SHA256SUMS`, path: join(dist, 'SHA256SUMS'),
    bytes: checksumBytes, digest: sha256(checksumBytes), immutable: true })

  const manifestPath = join(dist, 'manifest.json')
  const manifestBytes = Buffer.from(JSON.stringify({
    source_commit: sha,
    source_committed_at: committedAt,
    binaries: Object.fromEntries(files.slice(0, 2).map(file => [
      file.name.split('/').at(-1), { sha256: file.digest, size: file.bytes.length,
        url: new URL(file.name, distribution.publicUrl).href },
    ])),
  }, null, 2) + '\n')
  writeFileSync(manifestPath, manifestBytes)
  files.push({ name: `${sha}/manifest.json`, path: manifestPath,
    bytes: manifestBytes, digest: sha256(manifestBytes), immutable: true, manifest: true })

  return { sha, files, committedAt }
}

export async function verifyPublicFile(file, fetcher = fetch) {
  // Go binaries are small; verify their entire public HTTP representation.
  const response = await fetcher(new URL(file.name, distribution.publicUrl), {
    redirect: 'error', signal: AbortSignal.timeout(30_000), cache: 'no-store',
  })
  if (response.status !== 200 || sha256(Buffer.from(await response.arrayBuffer())) !== file.digest) {
    throw new Error(`Public artifact integrity check failed: ${file.name}`)
  }
}

// ---------------------------------------------------------------------------
// S3 publishing
// ---------------------------------------------------------------------------

export async function publishCandidate(candidate, storage, verify = verifyPublicFile) {
  const previous = await storage.read('latest.json')
  if (previous !== null) {
    let manifest
    try { manifest = JSON.parse(previous.toString('utf8')) } catch { throw new Error('Invalid latest.json in S3.') }
    if (!commitPattern.test(manifest.source_commit) || !Number.isSafeInteger(manifest.source_committed_at)) {
      throw new Error('Invalid latest.json revision metadata.')
    }
    if (manifest.source_committed_at > candidate.committedAt ||
      (manifest.source_committed_at === candidate.committedAt && manifest.source_commit !== candidate.sha)) {
      throw new Error('Refusing to replace a newer or conflicting published Dozor release.')
    }
  }

  // Immutable, SHA-addressed objects are the installation source of truth.
  // All updates to stable aliases happen after verified immutable uploads.
  for (const file of candidate.files) {
    const existing = await storage.head(file.name)
    if (existing !== null) {
      if (existing.Metadata?.sha256 !== file.digest || existing.ContentLength !== file.bytes.length) {
        throw new Error(`Immutable artifact already exists with different content: ${file.name}`)
      }
    } else {
      await storage.put(file.name, file.path, {
        sha256: file.digest,
        cacheControl: 'public, max-age=31536000, immutable',
        contentType: file.manifest ? 'application/json' : 'application/octet-stream',
      })
    }
    await verify(file)
  }

  // Human-friendly stable names. Never use them to install:
  // the immutable manifest points to SHA-addressed binaries, avoiding
  // race conditions while the aliases are being replaced.
  for (const file of candidate.files.slice(0, 2)) {
    const name = file.name.split('/').at(-1)
    await storage.put(name, file.path, {
      sha256: file.digest,
      cacheControl: 'no-cache, max-age=0, must-revalidate',
      contentType: 'application/octet-stream',
    })
    await verify({ ...file, name })
  }

  // Single final PUT switches the installation feed to this verified release.
  const manifest = candidate.files.find(file => file.manifest)
  await storage.put('latest.json', manifest.path, {
    sha256: manifest.digest,
    cacheControl: 'no-cache, max-age=0, must-revalidate',
    contentType: 'application/json',
  })
  await verify({ ...manifest, name: 'latest.json' })
}

// ---------------------------------------------------------------------------
// Storage factory
// ---------------------------------------------------------------------------

export function createStorage(env, _workspace, distributionOverride, fetcher = fetch) {
  const accessKeyId = env.S3_ACCESS_KEY_ID
  const secretAccessKey = env.S3_SECRET_ACCESS_KEY
  if (!accessKeyId || !secretAccessKey) {
    throw new Error('S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY are required in the publication step.')
  }

  const cfg = distributionOverride ?? distribution

  const client = new S3Client({
    accessKeyId: accessKeyId,
    secretAccessKey: secretAccessKey,
    endpoint: cfg.endpoint,
    bucket: cfg.bucket,
    region: cfg.region,
    virtualHostedStyle: false,
  })

  async function request(method, key, options = {}, missingAllowed = false) {
    for (let attempt = 1; attempt <= 3; attempt++) {
      const presignedUrl = client.presign(cfg.prefix + key, { method, expiresIn: 900,
        ...(options.headers?.['content-type'] ? { type: options.headers['content-type'] } : {}) })
      let response
      try {
        response = await fetcher(presignedUrl, { ...options, method, redirect: 'error', signal: AbortSignal.timeout(180_000) })
      } catch {
        if (attempt < 3) { await Bun.sleep(attempt * 1000); continue }
        throw new Error(`S3 ${method} failed [NetworkOrTlsError].`)
      }
      if (response.ok) return response
      const body = await response.text()
      const code = body.match(/<Code>([A-Za-z0-9]+)<\/Code>/)?.[1]
      if (missingAllowed && response.status === 404 && (!code || code === 'NoSuchKey')) return null
      if ((response.status === 429 || response.status >= 500) && attempt < 3) {
        await Bun.sleep(attempt * 1000)
        continue
      }
      const allowed = new Set(['AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'NoSuchBucket',
        'RequestTimeTooSkewed', 'AuthorizationHeaderMalformed', 'InvalidArgument', 'NoSuchKey'])
      throw new Error(`S3 ${method} failed [HTTP ${response.status}${allowed.has(code) ? ` ${code}` : ''}].`)
    }
  }
  return {
    async read(key) {
      const response = await request('GET', key, {}, true)
      return response === null ? null : Buffer.from(await response.arrayBuffer())
    },
    async head(key) {
      const response = await request('HEAD', key, {}, true)
      return response === null ? null : { ContentLength: Number(response.headers.get('content-length')),
        Metadata: { sha256: response.headers.get('x-amz-meta-sha256') } }
    },
    async put(key, path, metadata) {
      await request('PUT', key, { body: readFileSync(path), headers: {
        'content-type': metadata.contentType, 'cache-control': metadata.cacheControl,
        'x-amz-meta-sha256': metadata.sha256,
      } })
    },
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

export function configForPechka(candidate) {
  const lines = [
    'PECHKA_DOZOR_AGENT_FEATURE_ENABLED=true',
    `PECHKA_DOZOR_AGENT_MANIFEST_URL=${distribution.publicUrl}latest.json`,
  ]
  if (candidate) {
    const binNames = ['dozor-agent-linux-amd64', 'dozor-agent-linux-arm64']
    const entry = candidate.files?.find(f => f.name.endsWith('manifest.json'))
    if (entry) {
      const manifest = JSON.parse(entry.bytes.toString('utf8'))
      for (const [name, info] of Object.entries(manifest.binaries || {})) {
        if (info?.sha256) {
          lines.push(`PECHKA_DOZOR_SDK_SHA=${name}=${info.sha256}`)
        }
      }
    }
  }
  return lines.join('\n')
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    validateEnv(['S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'])

    const sha = process.env.GITHUB_SHA || process.env.DOZOR_RELEASE_SHA
    if (!commitPattern.test(sha ?? '')) throw new Error('GITHUB_SHA or DOZOR_RELEASE_SHA must be a 40-character commit.')
    const storage = createStorage(process.env)
    if (process.argv.includes('--check-access')) {
      await storage.read(`${sha}/manifest.json`)
      console.log('S3 read access verified.')
    } else {
      const dist = join(import.meta.dir, '../dist/pechka')
      const committedAt = Number(process.env.DOZOR_SOURCE_COMMIT_TS)
      if (!Number.isSafeInteger(committedAt) || committedAt <= 0) throw new Error('DOZOR_SOURCE_COMMIT_TS is required.')
      const candidate = loadCandidate(dist, sha, committedAt)
      await publishCandidate(candidate, storage)
      const config = configForPechka(candidate)
      console.log('Published and verified Go Agent (stable URLs + latest.json). Configure Pechka:')
      console.log(config)
      if (process.env.GITHUB_STEP_SUMMARY) {
        appendFileSync(process.env.GITHUB_STEP_SUMMARY,
          '## Dozor Agent S3 publication\n\n' +
          'Public artifacts verified. Copy these pinned settings into the Pechka environment:\n\n' +
          '```dotenv\n' + config + '\n```\n')
      }
    }
  } catch (error) {
    // Never print presigned S3 URLs, request bodies or credentials.
    console.error(error.message)
    process.exitCode = 1
  }
}
