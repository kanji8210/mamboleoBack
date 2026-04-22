# Kenya Breaking News Source Mapping

## Tier 1: Official Security & Government Sources
| # | Source Name | Type | Method | URL/Handle | Notes |
|---|------------|------|--------|------------|-------|
| 1 | Kenya Defence Forces (KDF) | Website | Scrape | https://www.mod.go.ke/all-news/ | Official military news |
| 2 | KDF X (Twitter) | X | X API/Scrape | @kdfinfo | Official X handle |
| 3 | National Police Service (NPS) | Website | Scrape | [NPS](https://www.kenyapolice.go.ke/news) | Official police news |
| 4 | NPS X | X | X API/Scrape | @NPSOfficial_KE | Official X handle |
| 5 | Interior Ministry | Website/X | Scrape/X API | [Interior](https://interior.go.ke/newsroom/) / @InteriorKE | |
| 6 | Gov Spokesperson | X | X API/Scrape | @SpokespersonGoK | |
| 7 | State House Kenya | X | X API/Scrape | @StateHouseKenya | |
| 8 | Kenya Red Cross | X | X API/Scrape | @KenyaRedCross | Disaster alerts |

## Tier 2: Mainstream Media Outlets
| # | Source Name | Type | Method | URL/Handle | Notes |
|---|------------|------|--------|------------|-------|
| 9 | Nation Media Group | Website/X | RSS/Scrape/X API | https://nation.africa/kenya / @dailynation | RSS available |
| 10 | Citizen Digital | Website/X | RSS/Scrape/X API | https://citizen.digital/ / @citizentvkenya | RSS available |
| 11 | Standard Media | Website/X | RSS/Scrape/X API | [Standard](https://www.standardmedia.co.ke/) / @StandardKenya | |
| 12 | KTN News Kenya | X | X API/Scrape | @KTNNewsKE | |
| 13 | KBC Channel 1 | X | X API/Scrape | @KBCChannel1 | |
| 14 | Capital FM Kenya | X | X API/Scrape | @CapitalFMKenya | |
| 15 | The Star Kenya | X | X API/Scrape | @TheStarKenya | |
| 16 | Business Daily Africa | X | X API/Scrape | @BD_Africa | |
| 17 | Radio Africa Group | X | X API/Scrape | Classic 105, Kiss FM, Radio Jambo | |
| 18 | Inooro TV | Website | Scrape | | Kikuyu news |
| 19 | Kameme FM/TV | Website | Scrape | | Kikuyu news |
| 20 | NTV Kenya | X | X API/Scrape | @ntvkenya | |

## Tier 3: Influential Bloggers & Digital Platforms
| # | Source Name | Type | Method | URL/Handle | Notes |
|---|------------|------|--------|------------|-------|
| 21 | Mutembei TV | Facebook/YouTube | FB/YouTube API | | Political news |
| 22 | Robert Alai | X | X API/Scrape | @RobertAlai | |
| 23 | Cyprian Nyakundi | Blog/X | Scrape/X API | @C_NyakundiH | |
| 24 | Gabriel Oguda | X | X API/Scrape | @gabrieloguda | |
| 25 | Edwin Sifuna | X | X API/Scrape | @edwinsifuna | |
| 26 | Dennis Itumbi | X | X API/Scrape | @OleItumbi | |
| 27 | Kenyans.co.ke | Website/X | RSS/Scrape/X API | https://www.kenyans.co.ke/ / @Kenyans | |
| 28 | Tuko News | Website/X | RSS/Scrape/X API | https://www.tuko.co.ke/ / @Tuko_co_ke | |
| 29 | Ghafla Kenya | Website | Scrape | | Celebrity/political |
| 30 | Facebook community pages | Facebook | FB API/Scrape | e.g. Nairobi News Alerts | |
| 31 | Your FB Page | Facebook | FB API/Scrape | https://www.facebook.com/profile.php?id=100072147501060 | |

---

- "Type" = Website, X (Twitter), Facebook, YouTube, Blog
- "Method" = RSS (if available), Scrape (HTML), X API, Facebook API, YouTube API
- For each, scraper implementation will depend on method and API access.
- All sources are tagged by tier for trust ranking.
