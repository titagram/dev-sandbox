# Graph Schema

The sandbox graph combines:

- Graphify AST nodes and relationships.
- Adapter sidecar relationships.
- Optional Neo4j projection.

All node and edge sources must stay inside the configured project root unless the edge belongs to a sandbox-sidecar metadata node.

Common relationship types:

- `references`
- `route_handled_by`
- `doctrine_relation`
- `template_reference`
- `service_declares`
- `import_dependency`
- `package_dependency`
