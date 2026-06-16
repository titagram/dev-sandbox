from __future__ import annotations

import os

from mcp.server.fastmcp import FastMCP

from devboard_plugin.mcp_tools import TOOL_REGISTRY


mcp = FastMCP("DevBoard", json_response=True)

for name, tool in TOOL_REGISTRY.items():
    mcp.tool(name=name)(tool)


def main() -> None:
    transport = os.environ.get("DEVBOARD_MCP_TRANSPORT", "stdio")
    mcp.run(transport=transport)


if __name__ == "__main__":
    main()
