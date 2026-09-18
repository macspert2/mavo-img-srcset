#!/usr/bin/env python3
"""
Prints a normalised, diffable summary of every content image on a page.

Used to compare the rendered output before and after a change: capture a
baseline, deploy, capture again, diff. Attribute order and whitespace are
normalised so only real differences show.

    tools/snapshot-images.py <url> [--check] > before.txt
    # deploy, purge
    tools/snapshot-images.py <url> > after.txt
    diff -u before.txt after.txt
"""
import re, sys, urllib.request, urllib.error

CHECK = '--check' in sys.argv          # also HEAD every URL and report 404s

def get(url):
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0', 'Accept': 'text/html',
        'Cache-Control': 'no-cache',
    })
    return urllib.request.urlopen(req, timeout=30).read().decode('utf-8', 'replace')

def attr(tag, name):
    m = re.search(rf'\b{name}=(["\'])(.*?)\1', tag, re.S)
    return re.sub(r'\s+', ' ', m.group(2)).strip() if m else None

def widths(srcset):
    if not srcset:
        return None
    return ' '.join(sorted(re.findall(r'(\d+)w', srcset), key=int, reverse=True))

def fmt(url):
    return url.rsplit('/', 1)[-1] if url else None

def status(url):
    """HEAD a URL and return its status code, or the error."""
    req = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'Mozilla/5.0'})
    try:
        return urllib.request.urlopen(req, timeout=20).status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception as e:
        return str(e)

broken = []

def check_urls(tag):
    """Every URL the browser could actually request from this tag."""
    urls = []
    src = attr(tag, 'src')
    if src:
        urls.append(src)
    for u, _ in re.findall(r'(\S+)\s+(\d+w)', attr(tag, 'srcset') or ''):
        urls.append(u.rstrip(','))
    for u in dict.fromkeys(urls):
        code = status(u)
        if code != 200:
            broken.append((code, u))

html = get([a for a in sys.argv[1:] if not a.startswith('--')][0])
body = html[html.find('<article'):] if '<article' in html else html

print(f'# {sys.argv[1]}')
n = 0
for m in re.finditer(r'<(figure[^>]*>\s*)?<img[^>]*?>(\s*<figcaption[^>]*>.*?</figcaption>)?', body, re.S):
    block = m.group(0)
    tag = re.search(r'<img[^>]*?>', block, re.S).group(0)
    src = attr(tag, 'src')
    if not src or '/wp-content/uploads/' not in src:
        continue
    n += 1
    cls = ' '.join(sorted((attr(tag, 'class') or '').split()))
    cap = re.search(r'<figcaption[^>]*>(.*?)</figcaption>', block, re.S)
    print(f'\n[{n}] {fmt(src)}')
    print(f'    class        : {cls}')
    print(f'    srcset widths: {widths(attr(tag, "srcset"))}')
    print(f'    srcset webp  : {"yes" if ".webp" in (attr(tag, "srcset") or "") else "no"}')
    print(f'    src webp     : {"yes" if src.endswith(".webp") else "no"}')
    print(f'    sizes        : {attr(tag, "sizes")}')
    print(f'    dimensions   : {attr(tag, "width")}x{attr(tag, "height")}')
    print(f'    loading      : {attr(tag, "loading")}')
    print(f'    decoding     : {attr(tag, "decoding")}')
    print(f'    fetchpriority: {attr(tag, "fetchpriority")}')
    print(f'    in figure    : {"yes" if block.lstrip().startswith("<figure") else "no"}')
    if cap:
        print(f'    caption      : {re.sub(r"<[^>]+>", "", cap.group(1)).strip()[:60]}')
    if CHECK:
        check_urls(tag)
print(f'\n# {n} content image(s)')
if CHECK:
    if broken:
        print(f'# {len(broken)} BROKEN URL(S):')
        for code, u in broken:
            print(f'#   {code}  {u}')
    else:
        print('# every src and srcset URL returns 200')
