---
title: "first strokes on serverless"
date: 2026-05-19
slug: "2026-05-19-serverless-first-strokes"
summary: "Started laying down the serverless surface — early services, UI, and jobs for running functions, plus the usual tests."
tags: [serverless, services, ui, jobs]
published: true
---

The whole day fits under one word, and that word was the commit message: **serverless**. Four commits, and they mark the beginning of dply learning to run functions, not just servers and sites.

This is the third leg of the managed-compute story. There's Cloud (managed containers), Edge (static and SSG at the edge), and now Serverless — functions-as-a-service. Today was the first strokes: services, a bit of UI, the jobs that'll eventually deploy and invoke a function, and tests sketching out the expected behavior.

It's early. The areas — services, Livewire UI, jobs, a few components — read like a foundation being poured rather than a building going up. Which is right. You don't get a serverless product on day one; you get the contracts and the deploy path and the shape of the screens, and then you spend a while making them real.

## why bother with another runtime

The temptation with a platform like this is to do one thing — say, PHP on a VM — really well and stop. But the bet I'm making is that the value is in *not* making people choose their platform up front. Servers, containers, edge, functions: same workspace, same deploy story. Serverless is the piece that makes "just run my code" mean something even when there's no server to point at.

So today's a small entry, but a meaningful one — the first commit with "serverless" on it. There'll be a lot more of those. The hard parts (adapters, the create-and-deploy gap, billing) are all still ahead.
