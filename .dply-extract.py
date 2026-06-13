#!/usr/bin/env python3
"""
Extract a set of methods + properties from a Livewire component into a Concerns
trait. Verbatim move (names unchanged) so snapshots + wire:* bindings still
resolve against the composed class.

Usage: extract.py <src_php> <ConcernsNamespace> <TraitName> <method_spec> <prop_spec>
  method_spec: comma list of exact method names, OR a single substring token
  prop_spec:   comma list of property-name prefixes (may be empty "")
"""
import os
import re
import sys

src = sys.argv[1]
ns = sys.argv[2]          # e.g. App\Livewire\Sites\Concerns
trait_name = sys.argv[3]
method_spec = sys.argv[4]
prop_spec = sys.argv[5]
_rel = ns[len("App\\"):] if ns.startswith("App\\") else ns
out = "app/" + _rel.replace("\\", "/") + "/" + trait_name + ".php"

explicit_methods = set(method_spec.split(",")) if "," in method_spec else None
method_token = None if explicit_methods else method_spec
prop_prefixes = [p for p in prop_spec.split(",") if p]


def mmatch(name):
    return name in explicit_methods if explicit_methods is not None else method_token.lower() in name.lower()


def pmatch(name):
    return any(name.startswith(p) for p in prop_prefixes)


lines = open(src).read().split("\n")
n = len(lines)
COMMENT_RE = re.compile(r"^\s*(/\*\*|\*|\*/|//|#\[)")
METHOD_RE = re.compile(r"^    (public|protected|private)(\s+static)?\s+function\s+(\w+)")
PROP_RE = re.compile(r"^    public\s+[^=();]*\$(\w+)")
taken = [False] * n


def leading(start):
    i = start - 1
    while i >= 0 and COMMENT_RE.match(lines[i]):
        i -= 1
    return i + 1


extracted = []
i = 0
while i < n:
    m = METHOD_RE.match(lines[i])
    if m and mmatch(m.group(3)):
        end = i + 1
        heredoc = None
        while end < n:
            ln = lines[end]
            if heredoc is None:
                hm = re.search(r"<<<[\"']?(\w+)[\"']?\s*$", ln)
                if hm:
                    heredoc = hm.group(1)
                elif ln == "    }":
                    break
            elif re.match(r"^\s*" + re.escape(heredoc) + r"\b", ln):
                heredoc = None
            end += 1
        s = leading(i)
        extracted.append(("m", s, "\n".join(lines[s:end + 1])))
        for k in range(s, end + 1):
            taken[k] = True
        i = end + 1
        continue
    i += 1
i = 0
while i < n:
    if taken[i]:
        i += 1
        continue
    m = PROP_RE.match(lines[i])
    if m and pmatch(m.group(1)):
        end = i
        while end < n and not lines[end].rstrip().endswith(";"):
            end += 1
        s = leading(i)
        extracted.append(("p", s, "\n".join(lines[s:end + 1])))
        for k in range(s, end + 1):
            taken[k] = True
        i = end + 1
        continue
    i += 1

if not extracted:
    print("NOTHING MATCHED")
    sys.exit(1)

extracted.sort(key=lambda x: x[1])
props = [t for kind, s, t in extracted if kind == "p"]
methods = [t for kind, s, t in extracted if kind == "m"]

body = "\n".join(t for _, _, t in extracted)
use_map = {}
for ln in lines:
    um = re.match(r"^use\s+([A-Za-z0-9_\\]+\\(\w+))(\s+as\s+\w+)?;", ln)
    if um:
        use_map[um.group(2)] = ln
referenced = set(re.findall(r"\b([A-Z]\w+)\b", body))
needed_set = {use_map[i] for i in referenced if i in use_map}
# Same-namespace siblings: the source could reference classes in its own
# namespace by short name (no `use` needed there). Once moved to a sub-namespace
# those short names resolve wrong, so add explicit imports for any referenced
# identifier that matches a sibling .php file in the source directory.
src_ns = None
for ln in lines:
    nm = re.match(r"^namespace\s+([A-Za-z0-9_\\]+);", ln)
    if nm:
        src_ns = nm.group(1)
        break
src_dir = os.path.dirname(src) or "."
src_base = os.path.splitext(os.path.basename(src))[0]
if src_ns:
    for ident in referenced:
        if ident in use_map or ident == src_base:
            continue
        if os.path.exists(os.path.join(src_dir, ident + ".php")):
            needed_set.add(f"use {src_ns}\\{ident};")
needed = sorted(needed_set)

header = f"""<?php

declare(strict_types=1);

namespace {ns};

{chr(10).join(needed)}

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait {trait_name}
{{
"""
open(out, "w").write(header + "\n".join(props) + "\n\n" + "\n\n".join(methods) + "\n}\n")

kept, blanks = [], 0
for idx, ln in enumerate(lines):
    if taken[idx]:
        continue
    if ln.strip() == "":
        blanks += 1
        if blanks <= 2:
            kept.append(ln)
    else:
        blanks = 0
        kept.append(ln)
open(src, "w").write("\n".join(kept))
print(f"{trait_name}: {len(methods)}m+{len(props)}p, {len(needed)} imports -> src now {len(kept)} lines")
