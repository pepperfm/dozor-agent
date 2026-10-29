// Port of StreamsPanel Player's verified Beget S3 publisher.
// The Dozor Go build is content-addressed by the Git commit that built the PHP SDK.
import { createHash } from 'node:crypto'
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { S3Client } from 'bun'
import { pathToFileURL } from 'node:url'

export const distribution = Object.freeze({
  endpoint: 'https://s3.ru1.storage.beget.cloud',
  bucket: 'd12436381c44-spp',
  region: 'ru1',
  prefix: 'dozor-agent/linux/',
  publicUrl: 'https://s3.streams-panel.ru/dozor-agent/linux/',
})

const sha256 = bytes => createHash('sha256').update(bytes).digest('hex')
const commitPattern = /^[a-f0-9]{40}$/
const binaries = ['dozor-agent-linux-amd64', 'dozor-agent-linux-arm64']

export function loadCandidate(dist, sha) {
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
    binaries: Object.fromEntries(files.slice(0, 2).map(file => [
      file.name.split('/').at(-1), { sha256: file.digest, size: file.bytes.length,
        url: new URL(file.name, distribution.publicUrl).href },
    ])),
  }, null, 2) + '\n')
  writeFileSync(manifestPath, manifestBytes)
  files.push({ name: `${sha}/manifest.json`, path: manifestPath,
    bytes: manifestBytes, digest: sha256(manifestBytes), immutable: true, manifest: true })

  return { sha, files }
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

export async function publishCandidate(candidate, storage, verify = verifyPublicFile) {
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
    // Even if S3 HEAD matches a previous upload, verify the publicly served bytes.
    await verify(file)
  }
}

export function createStorage(env, _workspace, fetcher = fetch) {
  if (!env.AWS_ACCESS_KEY_ID || !env.AWS_SECRET_ACCESS_KEY) {
    throw new Error('SPP_S3_ACCESS_KEY_ID and SPP_S3_SECRET_ACCESS_KEY are required in the publication step.')
  }
  const client = new S3Client({
    accessKeyId: env.AWS_ACCESS_KEY_ID,
    secretAccessKey: env.AWS_SECRET_ACCESS_KEY,
    endpoint: distribution.endpoint, bucket: distribution.bucket, region: distribution.region,
    virtualHostedStyle: false,
  })
  async function request(method, key, options = {}, missingAllowed = false) {
    for (let attempt = 1; attempt <= 3; attempt++) {
      // Keep signed URLs inside this adapter: they contain credentials and must never be logged.
      const url = client.presign(distribution.prefix + key, { method, expiresIn: 900,
        ...(options.headers?.['content-type'] ? { type: options.headers['content-type'] } : {}) })
      let response
      try {
        response = await fetcher(url, { ...options, method, redirect: 'error', signal: AbortSignal.timeout(180_000) })
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


export function configForPechka(candidate) {
  const digest = name => candidate.files.find(file => file.name.endsWith('/' + name))?.digest
  const url = name => new URL(`${candidate.sha}/${name}`, distribution.publicUrl).href
  return [
    'PECHKA_DOZOR_AGENT_FEATURE_ENABLED=true',
    `PECHKA_DOZOR_AGENT_AMD64_URL=${url('dozor-agent-linux-amd64')}`,
    `PECHKA_DOZOR_AGENT_AMD64_SHA256=${digest('dozor-agent-linux-amd64')}`,
    `PECHKA_DOZOR_AGENT_ARM64_URL=${url('dozor-agent-linux-arm64')}`,
    `PECHKA_DOZOR_AGENT_ARM64_SHA256=${digest('dozor-agent-linux-arm64')}`,
    `PECHKA_DOZOR_SDK_SHA=${candidate.sha}`,
  ].join('\n')
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const sha = process.env.GITHUB_SHA || process.env.DOZOR_RELEASE_SHA
    if (!commitPattern.test(sha ?? '')) throw new Error('GITHUB_SHA or DOZOR_RELEASE_SHA must be a 40-character commit.')
    const storage = createStorage(process.env)
    if (process.argv.includes('--check-access')) {
      await storage.read(`${sha}/manifest.json`)
      console.log('Beget S3 read access verified.')
    } else {
      const dist = join(import.meta.dir, '../dist/pechka')
      const candidate = loadCandidate(dist, sha)
      await publishCandidate(candidate, storage)
      const config = configForPechka(candidate)
      console.log('Published and verified Go Agent on Beget S3. Use these values in Pechka .env:')
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
