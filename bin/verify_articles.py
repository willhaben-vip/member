#!/usr/bin/env python3
"""
Article Verification Script

This script verifies that all articles from a seller's JSON file are properly
included in the index.htm file. Uses only standard Python libraries.
"""

import os
import sys
import json
import re
from pathlib import Path
from urllib.parse import urlparse

def extract_seller_id_from_path(path=None):
    """Extract seller ID from the current directory name."""
    if path is None:
        path = os.getcwd()
    
    json_files = list(Path(path).glob("*.json"))
    for file in json_files:
        if file.stem.isdigit():
            return file.stem
    return None

def load_seller_json(seller_id):
    """Load the seller JSON data."""
    json_path = f"{seller_id}.json"
    try:
        with open(json_path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except FileNotFoundError:
        print(f"Error: Seller JSON file '{json_path}' not found.")
        sys.exit(1)
    except json.JSONDecodeError:
        print(f"Error: Unable to parse JSON from file '{json_path}'.")
        sys.exit(1)

def extract_articles_from_json(json_data):
    """Extract article details from the seller JSON data."""
    articles = []
    
    for item in json_data:
        if '@type' in item and item['@type'] == 'ItemList':
            for list_item in item.get('itemListElement', []):
                if 'item' in list_item and '@type' in list_item['item'] and list_item['item']['@type'] == 'Product':
                    product = list_item['item']
                    article = {
                        'id': product.get('sku', ''),
                        'name': product.get('name', ''),
                        'url': product.get('url', ''),
                        'price': product.get('offers', {}).get('price', '')
                    }
                    articles.append(article)
    
    return articles

def parse_index_html(index_path):
    """Parse the index.htm file using regex to extract article references."""
    try:
        with open(index_path, 'r', encoding='utf-8') as f:
            html_content = f.read()
    except FileNotFoundError:
        print(f"Error: index.htm file not found at '{index_path}'.")
        sys.exit(1)
    
    # Find all product items and extract their IDs and names
    all_articles = []
    
    # Split content into product items first
    product_items = re.findall(r'<div class="product-item".*?</div>\s*</div>', html_content, re.DOTALL)
    print(f"\nDebug: Found {len(product_items)} product items")
    
    for item in product_items:
        # Extract ID from meta-info
        id_match = re.search(r'ID:\s*(\d+)', item)
        # Extract name from title/link
        name_match = re.search(r'<h3>\s*<a[^>]*>([^<]+)</a>', item)
        
        if id_match and name_match:
            article_id = id_match.group(1)
            article_name = name_match.group(1).strip()
            all_articles.append({
                'id': article_id,
                'name': article_name
            })
            print(f"Debug: Found article - ID: {article_id}, Name: {article_name}")
    
    return all_articles

def verify_url_format(url):
    """Verify the format of a URL."""
    parsed = urlparse(url)
    
    if not parsed.scheme or not parsed.netloc:
        return False, "URL missing scheme or domain"
    
    if not re.match(r'https://www\.willhaben\.at/iad/kaufen-und-verkaufen/d/.*?-\d+/?', url):
        return False, "URL does not match expected willhaben article pattern"
    
    return True, "Valid URL format"

def main():
    """Main function."""
    seller_id = None
    index_path = "index.htm"
    
    if len(sys.argv) > 1:
        seller_id = sys.argv[1]
    
    if len(sys.argv) > 2:
        index_path = sys.argv[2]
    
    if seller_id is None:
        seller_id = extract_seller_id_from_path()
        if seller_id is None:
            print("Error: Unable to determine seller ID. Please provide it as an argument.")
            sys.exit(1)
    
    print(f"Verifying articles for seller ID: {seller_id}")
    print(f"Using index file: {index_path}")
    
    # Load and parse data
    json_data = load_seller_json(seller_id)
    json_articles = extract_articles_from_json(json_data)
    index_articles = parse_index_html(index_path)
    
    # Extract article IDs for comparison
    json_article_ids = {article['id']: article for article in json_articles}
    index_article_ids = {article['id']: article for article in index_articles}
    
    # Check for missing articles
    missing_articles = []
    for article_id, article in json_article_ids.items():
        if article_id not in index_article_ids:
            missing_articles.append(article)
    
    # Check for extra articles
    extra_articles = []
    for article_id, article in index_article_ids.items():
        if article_id not in json_article_ids:
            extra_articles.append(article)
    
    # Verify URLs
    url_issues = []
    for article_id in set(json_article_ids.keys()) & set(index_article_ids.keys()):
        json_article = json_article_ids[article_id]
        url_valid, url_message = verify_url_format(json_article['url'])
        if not url_valid:
            url_issues.append({
                'id': article_id,
                'name': json_article['name'],
                'issue': url_message
            })
    
    # Print results
    print("\n--- Article Verification Results ---")
    print(f"\nTotal articles in JSON: {len(json_articles)}")
    print(f"Total articles in index.htm: {len(index_articles)}")
    
    if missing_articles:
        print(f"\nWARNING: {len(missing_articles)} articles from JSON are missing in index.htm:")
        for article in missing_articles:
            print(f"  - ID: {article['id']}, Name: {article['name']}")
    else:
        print("\nAll articles from JSON are present in index.htm.")
    
    if extra_articles:
        print(f"\nINFO: {len(extra_articles)} articles in index.htm are not in JSON (may be normal if manually added):")
        for article in extra_articles:
            print(f"  - ID: {article['id']}, Name: {article['name']}")
    
    if url_issues:
        print(f"\nWARNING: {len(url_issues)} URL issues found:")
        for issue in url_issues:
            print(f"  - ID: {issue['id']}, Name: {issue['name']}, Issue: {issue['issue']}")
    else:
        print("\nAll URLs are properly formatted.")
    
    if not missing_articles and not url_issues:
        print("\nVERIFICATION SUCCESSFUL: All articles are properly indexed and URLs are correctly formatted.")
    else:
        print("\nVERIFICATION FAILED: Issues were found during verification. See above for details.")

if __name__ == "__main__":
    main()
