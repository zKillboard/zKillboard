#!/usr/bin/env bash

# Pull and source only the export lines from .bashrc
source <(grep -E '^\s*export\s+\w+=' ~/.bashrc | grep -v '^\s*#')

mongosh $mongoConnString --file js/watch.mjs

