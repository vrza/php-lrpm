#!/bin/sh
# infer the base directory from $0
basedir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
# run lrpm with an example configuration class
"${basedir}/bin/lrpm" 'PHPLRPM\Test\MockConfigurationSource'
