alright we will had an adjustment, think, ask, critique dont code, because we just had a vague requirement from client
here some information from question & ask from my stakeholder help me to achieve it : 

question : Could you provide more detail about the “Client” section? On the dashboard side
menu, I can see a list of clients, but there are no details about each client’s data.

answer : Each client will have their own set of data, example if its PAXI courier, we will feed for
online shopping orsmes doing retail in za. The AI gen will generate according to their
brand. It will also house all the clients campaigns or current content thats online that was
created for them

my tought : we will revamp several client fiels to full-fill this one, i think the relation is clear 1 client 
might be can have multiple brands, and each brand can have several campaigns, please check this one 

--------------------------------

question : Is there any additional detail regarding the Trending Now page? We agreed to get
the data from Apify, but are there any conditions/filters for determining what
counts as “trending”?

answer : Its just based on ‘whatstrending’ and ‘whostrending’ in ZA at the time.

my tought : we will develop a 3rd party integration servcie with apify to get data with some periodic like hourly or daily

--------------------------------

question : What happens after we click a post? There’s no design/spec provided for this flow.

answer : For now it will just link out to the post

my tought : our plan is deveop first that apify integration, then somehow save it into our database that later we can refer to it, 
and create like mansory grid to display it, with some like thumbnail, title, description, and some button to link out to the post, we will 

--------------------------------

question : Could you explain the AI Recommendations feature in more detail? Does it
generate content using AI, or does it surface social media feeds using AI?

answer : Use a generative tool, any cheap free one:
Scan the trend > Generate an example of the trend for the brand using the AI tool

my tought : we should develop the correct flow for client, brand first then we can develop this one, we will integrate with openrouter later