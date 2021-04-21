# PHP-LRPM - PHP Long Running Process Manager

PHP-LRPM is a library for managing long running PHP processes.

## Motivation

Dynamic management of long running processes. Similar in function to process managers like daemontools or supervisord, but integrates with PHP code.

Can periodically reload configuration, e.g. from a database, and start/stop proceses accordingly.

Currently not intended to be running as PID 1, nor was it tested in this role.

## Features

### Simple

TODO describe design

### Efficient

PHP-LRPM starts its subprocesses via fork/exec and subprocesses don’t daemonize. The operating system signals PHP-LRPM immediately when a process terminates.

### Flexible

Exponential backoff on process restart, unlike most other process managers that will try several times and fail the process until operator intervention.

## Usage

TODO describe usage

## Development

### Roadmap / TODO list

#### Improve metadata handling

PHP-LRPM keeps metadata in an associative array. For efficient lookups by PID, a separate index is maintained.

This functionality could be offloaded to a generic library that wraps a hash map and maintains secondary indexes (similar to how secondary keys in an SQL database work).

## Some name ideas that were considered

* Palermo
* polearm
* poolroom

* pillar-pm
* polar-pm
* plural-pm
* plier-pm
