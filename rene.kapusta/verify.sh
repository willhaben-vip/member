#!/bin/bash
# Verify key elements in the HTML file

echo "=== Verifying Link Structure ==="
echo "Product URLs:"
grep -o '"/d/[^"]*"' index.htm | head -n 3

echo -e "\n=== Checking Product Template ==="
sed -n '/productCard.innerHTML/,/`;/p' index.htm

echo -e "\n=== Verifying Product Count ==="
echo "Total products:"
grep -o 'title: "' index.htm | wc -l

echo -e "\n=== Checking Button Structure ==="
echo "Button template:"
grep -A 3 'class="btn btn-primary"' index.htm

chmod +x verify.sh && ./verify.sh
