#!/usr/bin/env python3
import json
from pprint import pprint
import requests
from requests.packages.urllib3.exceptions import InsecureRequestWarning
from requests_oauthlib import OAuth1
requests.packages.urllib3.disable_warnings(InsecureRequestWarning)

# WooCommerce API credentials
CONSUMER_KEY = "ck_d422a3df88947bc1be9c956542a285620d91e30c"
CONSUMER_SECRET = "cs_a211363f4ed45d22eb5da4ab4f163de3d1042d7a"
BASE_URL = "https://peaberrykids.com.au/wp-json/wc/v3"

def get_product_fields():
    print("Fetching products...")
    
    try:
        # Set up OAuth1 authentication
        auth = OAuth1(
            CONSUMER_KEY,
            CONSUMER_SECRET
        )
        
        # Make request to list products
        endpoint = f"{BASE_URL}/products"
        params = {'per_page': 1}
        print(f"Making request to: {endpoint}")
        
        # Make request with OAuth1 authentication
        response = requests.get(
            endpoint,
            params=params,
            auth=auth,
            verify=False
        )
        
        print(f"Response Status Code: {response.status_code}")
        print(f"Response Headers: {dict(response.headers)}")
        
        if response.status_code != 200:
            print(f"Error Response: {response.text}")
            return
        
        products = response.json()
        print(f"\nFound {len(products)} products")
        
        if not products:
            print("No products found")
            return
        
        product = products[0]
        print(f"\nAnalyzing product ID: {product.get('id', 'Unknown')}")
        
        print("\n=== Product Fields ===")
        print("\nBasic Fields:")
        for key, value in product.items():
            if not isinstance(value, (dict, list)):
                print(f"{key}: {value}")
        
        print("\nMeta Data:")
        if 'meta_data' in product:
            for meta in product['meta_data']:
                print(f"{meta['key']}: {meta['value']}")
        
        print("\nTaxonomies:")
        taxonomies = ['categories', 'tags', 'brands']
        for tax in taxonomies:
            if tax in product:
                print(f"\n{tax.title()}:")
                for term in product[tax]:
                    print(f"  - {term['name']} (ID: {term['id']})")
        
        # Save full output to file for reference
        output_file = 'woo_product_fields.json'
        with open(output_file, 'w') as f:
            json.dump(product, f, indent=2)
        print(f"\nFull product data saved to {output_file}")
    
    except Exception as e:
        print(f"Error in get_product_fields: {str(e)}")
        raise

if __name__ == "__main__":
    try:
        print("Starting WooCommerce API query...")
        get_product_fields()
    except Exception as e:
        print(f"Error: {str(e)}")
        import traceback
        print("\nFull error traceback:")
        traceback.print_exc()
