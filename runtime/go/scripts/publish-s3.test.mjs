import { afterEach, expect, it } from 'bun:test'
import { createHash } from 'node:crypto'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  createStorage, configForPechka, distribution, loadCandidate,
  publishCandidate, verifyPublicFile,
  setDistributionOverride,
} from './publish-s3.mjs'

const checksum = bytes => createHash('sha256').update(bytes).digest('hex')
const sha = 'a'.repeat(40)
const dirs = []

// Tests must not depend on S3_* variables exported in the developer shell.
const testDefaults = {
  endpoint: 'https://test.example.com',
  bucket: 'test-bucket',
  region: 'test-region',
  prefix: 'dozor-agent/linux/',
  publicUrl: 'https://public.test/dozor-agent/linux/',
}
setDistributionOverride(testDefaults)
afterEach(() => { setDistributionOverride(testDefaults) })

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function candidateFixture() {
  const dir = mkdtempSync(join(tmpdir(), 'dozor-s3-test-'))
  dirs.push(dir)
  const names = ['dozor-agent-linux-amd64', 'dozor-agent-linux-arm64']
  const entries = names.map(name => {
    const bytes = Buffer.from(`GO-BINARY-${name}-${'x'.repeat(96)}`)
    writeFileSync(join(dir, name), bytes)
    return { name, bytes, digest: checksum(bytes) }
  })
  writeFileSync(join(dir, 'SHA256SUMS'), entries.map(x => `${x.digest}  ${x.name}\n`).join(''))
  return { dir, entries, candidate: loadCandidate(dir, sha, 1700000000) }
}

function fakeStorage() {
  const records = []
  const objects = new Map()
  return {
    records, objects,
    async head(name) { return objects.get(name) ?? null },
    async read(name) { return objects.get(name)?.bytes ?? null },
    async put(name, path, metadata) {
      records.push('put:' + name)
      objects.set(name, { Metadata: { sha256: metadata.sha256 },
        ContentLength: readFileSync(path).length, bytes: readFileSync(path), ...metadata })
    },
  }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('produces pinned Linux artifacts and the Pechka settings from the same commit', () => {
  const { candidate, entries } = candidateFixture()
  expect(candidate.files).toHaveLength(4)
  expect(candidate.files.map(x => x.name)).toEqual([
    `${sha}/dozor-agent-linux-amd64`, `${sha}/dozor-agent-linux-arm64`,
    `${sha}/SHA256SUMS`, `${sha}/manifest.json`,
  ])
  const config = configForPechka(candidate)
  expect(config).toContain('PECHKA_DOZOR_AGENT_MANIFEST_URL=' + distribution.publicUrl + 'latest.json')
  expect(config).toContain('PECHKA_DOZOR_SDK_SHA=')
  const manifest = JSON.parse(readFileSync(join(candidate.files[0].path, '../manifest.json'), 'utf8'))
  expect(manifest.source_commit).toBe(sha)
  expect(manifest.source_committed_at).toBe(1700000000)
  expect(manifest.binaries['dozor-agent-linux-amd64'].sha256).toBe(entries[0].digest)
})

it('refuses wrong and duplicate checksum manifests before uploading', () => {
  const { dir } = candidateFixture()
  writeFileSync(join(dir, 'dozor-agent-linux-amd64'), 'tampered')
  expect(() => loadCandidate(dir, sha)).toThrow()
  expect(() => loadCandidate(dir, 'broken')).toThrow()
  writeFileSync(join(dir, 'SHA256SUMS'),
    checksum('something') + '  dozor-agent-linux-amd64\n' +
    checksum('something') + '  dozor-agent-linux-amd64\n')
  expect(() => loadCandidate(dir, sha)).toThrow('duplicate')
})

it('verifies public artifacts before uploading commit manifest', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await publishCandidate(candidate, storage, file => { storage.records.push('verify:' + file.name) })
  expect(storage.records).toEqual([
    'put:' + candidate.files[0].name, 'verify:' + candidate.files[0].name,
    'put:' + candidate.files[1].name, 'verify:' + candidate.files[1].name,
    'put:' + candidate.files[2].name, 'verify:' + candidate.files[2].name,
    'put:' + candidate.files[3].name, 'verify:' + candidate.files[3].name,
    'put:dozor-agent-linux-amd64', 'verify:dozor-agent-linux-amd64',
    'put:dozor-agent-linux-arm64', 'verify:dozor-agent-linux-arm64',
    'put:latest.json', 'verify:latest.json',
  ])
  expect(storage.objects.get('latest.json').cacheControl).toContain('no-cache')
  expect(storage.objects.get('dozor-agent-linux-amd64').cacheControl).toContain('no-cache')
  expect(storage.objects.get(candidate.files[0].name).cacheControl).toContain('immutable')
})

it('aborts before publishing the commit manifest after failed public verification', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await expect(publishCandidate(candidate, storage, async file => {
    if (file.name.endsWith('dozor-agent-linux-arm64')) throw new Error('public corrupt')
  })).rejects.toThrow('public corrupt')
  expect(storage.objects.has(candidate.files.at(-1).name)).toBeFalse()
  expect(storage.objects.has('latest.json')).toBeFalse()
})

it('is idempotent, and rejects content conflicts at a pinned URL', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await publishCandidate(candidate, storage, async () => {})
  storage.records.length = 0
  await publishCandidate(candidate, storage, async () => {})
  expect(storage.records).toEqual([
    'put:dozor-agent-linux-amd64',
    'put:dozor-agent-linux-arm64',
    'put:latest.json',
  ])
  storage.records.length = 0
  storage.objects.get(candidate.files[0].name).Metadata.sha256 = 'different'
  await expect(publishCandidate(candidate, storage, async () => {})).rejects.toThrow('different content')
  expect(storage.records).toEqual([])
})

it('verifies full unauthenticated HTTPS responses', async () => {
  const { candidate } = candidateFixture()
  const file = candidate.files[0]
  await verifyPublicFile(file, async (url, options) => {
    expect(url.href).toBe(distribution.publicUrl + file.name)
    expect(options.redirect).toBe('error')
    return new Response(file.bytes)
  })
  await expect(verifyPublicFile(file, async () => new Response('corrupt'))).rejects.toThrow('integrity')
  await expect(verifyPublicFile(file, async () => new Response('forbidden', { status: 403 })))
    .rejects.toThrow('integrity')
})

it('signs requests through the AWS SDK client and does not disclose credentials', async () => {
  expect(() => createStorage({})).toThrow('S3_ACCESS_KEY_ID')
  const sent = []
  const fakeClient = {
    async send(command) {
      sent.push(command)
      const error = new Error('AccessDenied: secret detail with private-secret and access-key')
      error.name = 'AccessDenied'
      error.$metadata = { httpStatusCode: 403 }
      throw error
    },
  }
  const storage = createStorage(
    { S3_ACCESS_KEY_ID: 'access-key', S3_SECRET_ACCESS_KEY: 'private-secret' },
    null,
    null,
    { client: fakeClient },
  )
  try {
    await storage.read('artifact')
    throw new Error('Expected S3 error')
  } catch (error) {
    expect(error.message).toContain('[HTTP 403 AccessDenied]')
    expect(error.message).not.toContain('private-secret')
    expect(error.message).not.toContain('access-key')
  }
  expect(sent[0].constructor.name).toBe('GetObjectCommand')
  expect(sent[0].input.Bucket).toBe(distribution.bucket)
  expect(sent[0].input.Key).toBe(distribution.prefix + 'artifact')
})

it('passes signed metadata on uploads and accepts distribution overrides', async () => {
  const sent = []
  const fakeClient = {
    async send(command) {
      sent.push(command)
      const error = new Error('denied')
      error.name = 'AccessDenied'
      error.$metadata = { httpStatusCode: 403 }
      throw error
    },
  }
  const overrides = { endpoint: 'https://custom.example.com', bucket: 'my-bucket', region: 'us-east-1' }
  setDistributionOverride(overrides)

  const storage = createStorage(
    { S3_ACCESS_KEY_ID: 'k', S3_SECRET_ACCESS_KEY: 's' },
    null,
    null,
    { client: fakeClient },
  )
  const dir = mkdtempSync(join(tmpdir(), 'dozor-s3-put-'))
  const filePath = join(dir, 'artifact.bin')
  writeFileSync(filePath, 'payload')
  try {
    await storage.put('artifact', filePath, {
      sha256: 'digest', contentType: 'application/octet-stream', cacheControl: 'no-cache',
    })
    throw new Error('Expected S3 error')
  } catch (error) {
    expect(error.message).toContain('[HTTP 403 AccessDenied]')
  }
  expect(sent[0].constructor.name).toBe('PutObjectCommand')
  expect(sent[0].input.Bucket).toBe('my-bucket')
  expect(sent[0].input.Key).toBe(distribution.prefix + 'artifact')
  // Signed by the SDK into SigV4, not sent as raw fetch headers.
  expect(sent[0].input.CacheControl).toBe('no-cache')
  expect(sent[0].input.Metadata).toEqual({ sha256: 'digest' })
  expect(sent[0].input.ContentType).toBe('application/octet-stream')

  expect(() => createStorage({})).toThrow('S3_ACCESS_KEY_ID')
})

it('maps missing objects to null and network failures to safe errors', async () => {
  const missing = {
    async send() {
      const error = new Error('NotFound')
      error.name = 'NoSuchKey'
      error.$metadata = { httpStatusCode: 404 }
      throw error
    },
  }
  const storage = createStorage({ S3_ACCESS_KEY_ID: 'k', S3_SECRET_ACCESS_KEY: 's' },
    null, null, { client: missing })
  expect(await storage.read('absent')).toBeNull()
  expect(await storage.head('absent')).toBeNull()

  const offline = { async send() { throw Object.assign(new Error('ECONNREFUSED with token'), { name: 'Error' }) } }
  const offlineStorage = createStorage({ S3_ACCESS_KEY_ID: 'k', S3_SECRET_ACCESS_KEY: 's' },
    null, null, { client: offline })
  await expect(offlineStorage.read('x')).rejects.toThrow('[HTTP Network]')
  await expect(offlineStorage.read('x')).rejects.not.toThrow('token')
})

it('refuses older or conflicting commits without mutating stable feed', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await publishCandidate(candidate, storage, async () => {})

  storage.records.length = 0
  const stale = { ...candidate, committedAt: 1699999999 }
  await expect(publishCandidate(stale, storage, async () => {})).rejects.toThrow('newer')
  expect(storage.records).toEqual([])

  const conflicting = { ...candidate, sha: 'b'.repeat(40) }
  await expect(publishCandidate(conflicting, storage, async () => {})).rejects.toThrow('conflicting')
  expect(storage.records).toEqual([])
})
