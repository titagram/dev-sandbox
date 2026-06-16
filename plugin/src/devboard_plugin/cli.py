import typer

app = typer.Typer(help="DevBoard local plugin")


@app.callback()
def main() -> None:
    """DevBoard local plugin command group."""


@app.command()
def version() -> None:
    typer.echo("devboard-plugin 0.1.0")
