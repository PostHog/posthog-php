#!/usr/bin/env node

import { appendFileSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

function getArg(name) {
    const index = process.argv.indexOf(`--${name}`)
    return index === -1 ? undefined : process.argv[index + 1]
}

function hasArg(name) {
    return process.argv.includes(`--${name}`)
}

function git(args, options = {}) {
    return execFileSync('git', args, { encoding: 'utf8', ...options }).trimEnd()
}

function parseMessage(message) {
    const [headline, ...bodyParts] = message.split('\n')
    return { headline, body: bodyParts.join('\n') }
}

function collectChanges() {
    const output = execFileSync('git', ['status', '--porcelain=v1', '-z', '--', '.'])
    const entries = output.toString('utf8').split('\0')
    const additions = new Set()
    const deletions = new Set()

    for (let i = 0; i < entries.length; i++) {
        const line = entries[i]
        if (!line) {
            continue
        }

        const indexStatus = line[0]
        const treeStatus = line[1]

        if (indexStatus === 'R' || treeStatus === 'R') {
            const newPath = line.slice(3)
            const oldPath = entries[++i]
            if (newPath) {
                additions.add(newPath)
            }
            if (oldPath) {
                deletions.add(oldPath)
            }
            continue
        }

        const filePath = line.slice(3)
        if (!filePath) {
            continue
        }

        if (/[AMT]/.test(indexStatus) || /[AMT]/.test(treeStatus) || (indexStatus === '?' && treeStatus === '?')) {
            additions.add(filePath)
        }

        if (indexStatus === 'D' || treeStatus === 'D') {
            deletions.add(filePath)
        }
    }

    return {
        additions: [...additions].sort(),
        deletions: [...deletions].sort(),
    }
}

function isRetryable(error) {
    const message = String(error?.message ?? error)
    return (
        /Something went wrong while executing your query/i.test(message) ||
        /HTTP 5\d\d/i.test(message) ||
        /ECONNRESET|ETIMEDOUT|EAI_AGAIN|fetch failed/i.test(message)
    )
}

async function createCommit({ token, repo, branch, message, additions, deletions, expectedHeadOid }) {
    const { headline, body } = parseMessage(message)
    const query = `
        mutation CreateCommitOnBranch($input: CreateCommitOnBranchInput!) {
            createCommitOnBranch(input: $input) {
                commit {
                    oid
                    url
                }
            }
        }
    `

    const variables = {
        input: {
            branch: {
                repositoryNameWithOwner: repo,
                branchName: branch,
            },
            message: {
                headline,
                body,
            },
            fileChanges: {
                additions: additions.map((filePath) => ({
                    path: filePath,
                    contents: readFileSync(filePath).toString('base64'),
                })),
                deletions: deletions.map((filePath) => ({ path: filePath })),
            },
            expectedHeadOid,
        },
    }

    const response = await fetch(process.env.GITHUB_GRAPHQL_URL || 'https://api.github.com/graphql', {
        method: 'POST',
        headers: {
            authorization: `Bearer ${token}`,
            'content-type': 'application/json',
            'user-agent': 'posthog-php-release-workflow',
        },
        body: JSON.stringify({ query, variables }),
    })

    const responseText = await response.text()
    let payload
    try {
        payload = JSON.parse(responseText)
    } catch (error) {
        throw new Error(`GitHub GraphQL returned non-JSON response: HTTP ${response.status} ${responseText}`)
    }

    if (!response.ok) {
        throw new Error(`GitHub GraphQL HTTP ${response.status}: ${JSON.stringify(payload)}`)
    }

    if (payload.errors?.length) {
        throw new Error(`GitHub GraphQL errors: ${payload.errors.map((error) => error.message).join('; ')}`)
    }

    return payload.data.createCommitOnBranch.commit
}

async function main() {
    const repo = getArg('repo') || process.env.GITHUB_REPOSITORY
    const branch = getArg('branch') || process.env.GITHUB_REF_NAME || 'main'
    const message = getArg('message') || process.env.COMMIT_MESSAGE
    const token = process.env.GITHUB_TOKEN || process.env.GH_TOKEN
    const dryRun = hasArg('dry-run')
    const retryAttempts = Number(process.env.COMMIT_RETRY_ATTEMPTS || '4')

    if (!repo) {
        throw new Error('Missing --repo or GITHUB_REPOSITORY')
    }
    if (!branch) {
        throw new Error('Missing --branch or GITHUB_REF_NAME')
    }
    if (!message) {
        throw new Error('Missing --message or COMMIT_MESSAGE')
    }
    if (!token && !dryRun) {
        throw new Error('Missing GITHUB_TOKEN or GH_TOKEN')
    }

    const expectedHeadOid = git(['rev-parse', 'HEAD'])
    const { additions, deletions } = collectChanges()

    console.log(`Repository: ${repo}`)
    console.log(`Branch: ${branch}`)
    console.log(`Expected head: ${expectedHeadOid}`)
    console.log(`Files to add/update: ${additions.length}`)
    for (const filePath of additions) {
        console.log(`  add ${filePath}`)
    }
    console.log(`Files to delete: ${deletions.length}`)
    for (const filePath of deletions) {
        console.log(`  delete ${filePath}`)
    }

    if (additions.length === 0 && deletions.length === 0) {
        console.log('No changes detected, exiting')
        return
    }

    if (dryRun) {
        console.log('Dry run complete; no commit created')
        return
    }

    let lastError
    for (let attempt = 1; attempt <= retryAttempts; attempt++) {
        try {
            const commit = await createCommit({ token, repo, branch, message, additions, deletions, expectedHeadOid })
            console.log(`Success. New commit: ${commit.url}`)

            if (process.env.GITHUB_OUTPUT) {
                appendFileSync(process.env.GITHUB_OUTPUT, `commit-url=${commit.url}\n`)
                appendFileSync(process.env.GITHUB_OUTPUT, `commit-hash=${commit.oid}\n`)
            }
            return
        } catch (error) {
            lastError = error
            if (attempt === retryAttempts || !isRetryable(error)) {
                throw error
            }

            const waitSeconds = Math.min(60, 5 * 2 ** (attempt - 1))
            console.warn(`Commit attempt ${attempt}/${retryAttempts} failed with a retryable error: ${error.message}`)
            console.warn(`Retrying in ${waitSeconds}s...`)
            await sleep(waitSeconds * 1000)
        }
    }

    throw lastError
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
