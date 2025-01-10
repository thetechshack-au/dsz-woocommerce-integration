#!/usr/bin/env python3
import requests
import csv
from typing import List, Set, Dict
import sys
from urllib.parse import quote

def get_categories(api_url: str, table_id: str, api_token: str) -> Set[str]:
    """Fetch all unique categories from Baserow."""
    categories = set()
    page = 1
    
    while True:
        # Build URL with pagination and select only Category field
        url = f"{api_url}/api/database/rows/table/{table_id}/?user_field_names=true&size=200&page={page}&select=Category"
        
        # Make request
        response = requests.get(
            url,
            headers={
                'Authorization': f'Token {api_token}',
                'Content-Type': 'application/json'
            }
        )
        
        # Check for errors
        if response.status_code != 200:
            print(f"Error fetching page {page}: {response.status_code}")
            print(response.text)
            break
            
        data = response.json()
        results = data.get('results', [])
        
        # Break if no more results
        if not results:
            break
            
        # Add categories to set
        for product in results:
            category = product.get('Category')
            if category:
                categories.add(category.strip())
        
        # Print progress
        print(f"Processed page {page}, found {len(categories)} unique categories so far")
        
        # Break if this is the last page
        if not data.get('next'):
            break
            
        page += 1
    
    return categories

def clean_category_name(name: str) -> str:
    """Clean category name by removing apostrophes."""
    return name.replace("'", "")

def parse_category(full_category: str) -> Dict[str, str]:
    """Parse a full category path into its components."""
    parts = [clean_category_name(part.strip()) for part in full_category.split(' > ')]
    
    return {
        'full_path': full_category,
        'top_category': parts[0],
        'parent_category': parts[1] if len(parts) > 2 else '',
        'category_name': parts[-1],
        'category_id': str(hash(full_category) & 0xffffffff)  # Simple numeric ID for reference
    }

def save_categories(categories: Set[str], output_file: str):
    """Save categories to CSV file with hierarchy information."""
    # Parse and sort categories
    parsed_categories = [parse_category(cat) for cat in categories]
    sorted_categories = sorted(parsed_categories, key=lambda x: x['full_path'])
    
    # Write to CSV
    with open(output_file, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow(['Category ID', 'Category Name', 'Parent Category', 'Top Category', 'Full Path'])
        for cat in sorted_categories:
            writer.writerow([
                cat['category_id'],
                cat['category_name'],
                cat['parent_category'],
                cat['top_category'],
                cat['full_path']
            ])
    
    print(f"\nSaved {len(categories)} categories to {output_file}")
    
    # Print category structure for verification
    print("\nCategory Structure:")
    current_parent = None
    for cat in sorted_categories:
        if cat['parent_category'] != current_parent:
            current_parent = cat['parent_category']
            print(f"\n{cat['top_category']} > {cat['parent_category']}")
        print(f"  - {cat['category_name']}")

def main():
    # Configuration
    if len(sys.argv) != 4:
        print("Usage: python get_baserow_categories.py <api_url> <table_id> <api_token>")
        sys.exit(1)
        
    api_url = sys.argv[1].rstrip('/')  # Remove trailing slash if present
    table_id = sys.argv[2]
    api_token = sys.argv[3]
    output_file = 'data/dsz-categories.csv'
    
    print("Fetching categories from Baserow...")
    categories = get_categories(api_url, table_id, api_token)
    
    print(f"\nFound {len(categories)} unique categories")
    save_categories(categories, output_file)

if __name__ == "__main__":
    main()
