import { afterEach, expect, it } from 'bun:test'
import { createHash } from 'node:crypto'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  createStorage, configForPechka, distribution, loadCandidate,
  publishCandidate, verifyPublicFile,
} from './publish-s3.mjs'

const checksum = bytes => createHash('sha256').update(bytes).digest('hex')
const sha = 'a'.repeat(40)
const dirs = []
afterEach(() => { for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true }) })

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
  return { dir, entries, candidate: loadCandidate(dir, sha) }
}

function fakeStorage() {
  const records = []
  const objects = new Map()
  return {
    records, objects,
    async head(name) { return objects.get(name) ?? null },
    async put(name, path, metadata) {
      records.push('put:' + name)
      objects.set(name, { Metadata: { sha256: metadata.sha256 },
        ContentLength: readFileSync(path).length, ...metadata })
    },
  }
}

it('produces pinned Linux artifacts and the Pechka settings from the same commit', () => {
  const { candidate, entries } = candidateFixture()
  expect(candidate.files).toHaveLength(4)
  expect(candidate.files.map(x => x.name)).toEqual([
    `${sha}/dozor-agent-linux-amd64`, `${sha}/dozor-agent-linux-arm64`,
    `${sha}/SHA256SUMS`, `${sha}/manifest.json`,
  ])
  const config = configForPechka(candidate)
  expect(config).toContain(`PECHKA_DOZOR_SDK_SHA=${sha}`)
  expect(config).toContain(`PECHKA_DOZOR_AGENT_AMD64_SHA256=${entries[0].digest}`)
  expect(config).toContain(`PECHKA_DOZOR_AGENT_ARM64_SHA256=${entries[1].digest}`)
  expect(config).toContain(distribution.publicUrl + sha)
  const manifest = JSON.parse(readFileSync(join(candidate.files[0].path, '../manifest.json'), 'utf8'))
  expect(manifest.source_commit).toBe(sha)
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
  ])
  expect(storage.objects.get(candidate.files[0].name).cacheControl).toContain('immutable')
})

it('aborts before publishing the commit manifest after failed public verification', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await expect(publishCandidate(candidate, storage, async file => {
    if (file.name.endsWith('dozor-agent-linux-arm64')) throw new Error('public corrupt')
  })).rejects.toThrow('public corrupt')
  expect(storage.objects.has(candidate.files.at(-1).name)).toBeFalse()
})

it('is idempotent, and rejects content conflicts at a pinned URL', async () => {
  const { candidate } = candidateFixture()
  const storage = fakeStorage()
  await publishCandidate(candidate, storage, async () => {})
  storage.records.length = 0
  await publishCandidate(candidate, storage, async () => {})
  expect(storage.records).toEqual([])
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

it('uses SPP path-style S3 signing and does not disclose credentials', async () => {
  expect(() => createStorage({})).toThrow('SPP_S3_ACCESS_KEY_ID')
  const signed = []
  const fakeFetch = async (url, options) => {
    signed.push({ url: new URL(url), options })
    return new Response('<Error><Code>AccessDenied</Code><Message>private-secret</Message></Error>',
      { status: 403 })
  }
  const storage = createStorage({ AWS_ACCESS_KEY_ID: 'access-key', AWS_SECRET_ACCESS_KEY: 'private-secret' },
    null, fakeFetch)
  try {
    await storage.read('artifact')
    throw new Error('Expected S3 error')
  } catch (error) {
    expect(error.message).toContain('[HTTP 403 AccessDenied]')
    expect(error.message).not.toContain('private-secret')
    expect(error.message).not.toContain('access-key')
  }
  expect(signed[0].url.pathname).toBe(
    '/' + distribution.bucket + '/' + distribution.prefix + 'artifact')
  expect(signed[0].url.searchParams.get('X-Amz-Signature')).toBeTruthy()
})
