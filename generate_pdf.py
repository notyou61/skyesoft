import sys
from pathlib import Path
from weasyprint import HTML, CSS
import logging

# Set up logging
logging.basicConfig(level=logging.ERROR)  # Change to DEBUG for more detailed logging if needed

def generate_pdf(artifact_id):
    base = Path(__file__).parent
    html_path = base / "output" / f"{artifact_id}.html"
    pdf_path = base / "output" / f"{artifact_id}.pdf"

    if not html_path.exists():
        logging.error(f"❌ HTML file not found: {html_path}")
        return

    try:
        html_content = HTML(filename=str(html_path))

        # Determine directories
        html_dir = html_path.parent
        docs_dir = html_dir.parent
        assets_dir = docs_dir / 'docs' / 'assets'

        # Paths to CSS files
        markdown_css_path = assets_dir / "github-markdown.css"
        custom_css_path = assets_dir / "style.css"
        pdf_css_path = assets_dir / "pdf.css"

        # Check CSS existence
        if not markdown_css_path.exists():
            logging.error(f"❌ markdown_css file not found: {markdown_css_path}")
            return
        if not custom_css_path.exists():
            logging.error(f"❌ custom_css file not found: {custom_css_path}")
            return
        if not pdf_css_path.exists():
            logging.error(f"❌ pdf_css file not found: {pdf_css_path}")
            return

        stylesheets = [
            CSS(filename=str(markdown_css_path)),
            CSS(filename=str(custom_css_path)),
            CSS(filename=str(pdf_css_path)),
        ]

        # Generate PDF
        html_content.write_pdf(
            str(pdf_path),
            stylesheets=stylesheets,
            base_url=str(html_dir)  # Important for relative asset paths
        )
        print(f"✅ PDF created at: {pdf_path}")

    except Exception as e:
        logging.error(f"❌ Error generating PDF: {e}")
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python generate_pdf.py <artifact_id>")
    else:
        generate_pdf(sys.argv[1])
